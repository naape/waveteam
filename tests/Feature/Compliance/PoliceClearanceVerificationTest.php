<?php

namespace Tests\Feature\Compliance;

use App\Actions\Compliance\PoliceClearanceVerification;
use App\Enums\DocumentStatus;
use App\Enums\PccVerificationStatus;
use App\Enums\PoliceClearanceStatus;
use App\Filament\Resources\PoliceClearances\Pages\ListPoliceClearances;
use App\Models\AuditLog;
use App\Models\PoliceClearanceRecord;
use App\Models\User;
use App\Notifications\TemplatedNotification;
use App\Support\Compliance\PoliceClearanceEligibility;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesApplicants;
use Tests\TestCase;

class PoliceClearanceVerificationTest extends TestCase
{
    use CreatesApplicants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00');
        Storage::fake('documents');
        $this->seed([RolesAndPermissionsSeeder::class, NotificationTemplateSeeder::class]);
    }

    private function upload(User $applicant, string $issued = '2026-10-20'): PoliceClearanceRecord
    {
        $this->actingAs($applicant)->post(route('applicant.police-clearance.store'), [
            'issue_date' => $issued, 'file' => $this->fakePdf('pcc.pdf'), 'confirm_genuine' => '1',
        ])->assertSessionHasNoErrors();

        return PoliceClearanceRecord::latest('id')->firstOrFail();
    }

    public function test_compliance_officer_approves_with_admin_set_dates(): void
    {
        Notification::fake();
        $officer = $this->makeStaff('Compliance Officer');
        $applicant = $this->makeApplicant();
        $record = $this->upload($applicant);
        $this->assertSame(PoliceClearanceStatus::PendingVerification, $applicant->applicantProfile->fresh()->policeClearanceStatus());

        app(PoliceClearanceVerification::class)->approve($record, $officer, Carbon::parse('2027-06-30'), Carbon::parse('2027-05-31'), 'Checked against NIC');

        $record->refresh();
        $profile = $applicant->applicantProfile->fresh();
        $this->assertSame(PccVerificationStatus::Approved, $record->verification_status);
        $this->assertSame('2027-06-30', $record->valid_until->toDateString());
        $this->assertSame('2027-05-31', $record->next_renewal_due->toDateString());
        $this->assertSame($officer->id, $record->verified_by);
        $this->assertSame(DocumentStatus::Verified, $record->document->status);
        $this->assertSame($record->id, $profile->current_police_clearance_id);
        $this->assertSame(PoliceClearanceStatus::Valid, $profile->policeClearanceStatus());
        Notification::assertSentTo($applicant, TemplatedNotification::class, fn ($n) => $n->templateKey === 'pcc.approved' && $n->data['renewal_date'] === '31 May 2027');
        $log = AuditLog::where('action', 'pcc.approved')->firstOrFail();
        $this->assertSame('2027-10-20', $log->new_values['suggested_valid_until']); // default 12 months recorded for audit
    }

    public function test_approval_date_rules(): void
    {
        $officer = $this->makeStaff('Compliance Officer');
        $record = $this->upload($this->makeApplicant());
        $verify = app(PoliceClearanceVerification::class);

        foreach ([
            [Carbon::parse('2026-10-01'), null, 'valid_until'],            // before issue date
            [Carbon::parse('2026-11-01'), null, 'valid_until'],            // already expired
            [Carbon::parse('2027-06-30'), Carbon::parse('2027-07-31'), 'next_renewal_due'], // renewal after expiry
            [Carbon::parse('2027-06-30'), Carbon::parse('2026-10-31'), 'next_renewal_due'], // renewal in the past
        ] as [$valid, $renewal, $field]) {
            try {
                $verify->approve($record, $officer, $valid, $renewal);
                $this->fail("Expected validation error on {$field}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }

        $this->assertSame(PccVerificationStatus::PendingVerification, $record->fresh()->verification_status);
    }

    public function test_only_verifiers_can_decide(): void
    {
        $record = $this->upload($this->makeApplicant());

        foreach (['Recruiter', 'HR Manager', 'Operations Manager'] as $role) {
            try {
                app(PoliceClearanceVerification::class)->approve($record, $this->makeStaff($role), Carbon::parse('2027-10-20'));
                $this->fail("{$role} must not verify");
            } catch (ValidationException) {
            }
        }

        $this->assertSame(PccVerificationStatus::PendingVerification, $record->fresh()->verification_status);
    }

    public function test_renewal_supersedes_previous_but_history_is_kept(): void
    {
        $officer = $this->makeStaff('Compliance Officer');
        $applicant = $this->makeApplicant();
        $verify = app(PoliceClearanceVerification::class);

        $first = $this->upload($applicant, '2026-01-10');
        $verify->approve($first, $officer, Carbon::parse('2027-01-10'));

        $second = $this->upload($applicant, '2026-10-25');
        // The old certificate stays in force while the renewal is pending.
        $this->assertSame(PoliceClearanceStatus::Valid, $applicant->applicantProfile->fresh()->policeClearanceStatus());

        $verify->approve($second, $officer, Carbon::parse('2027-10-25'));

        $this->assertNotNull($first->fresh()->superseded_at);
        $this->assertSame(PccVerificationStatus::Approved, $first->fresh()->verification_status, 'history unchanged');
        $this->assertSame($second->id, $applicant->applicantProfile->fresh()->current_police_clearance_id);
        $this->assertSame(2, PoliceClearanceRecord::count());
    }

    public function test_rejection_requires_reason_and_notifies(): void
    {
        Notification::fake();
        $officer = $this->makeStaff('Compliance Officer');
        $applicant = $this->makeApplicant();
        $record = $this->upload($applicant);
        $verify = app(PoliceClearanceVerification::class);

        try {
            $verify->reject($record, $officer, ' ');
            $this->fail('Reason required');
        } catch (ValidationException) {
        }

        $verify->reject($record, $officer, 'Name does not match NIC');

        $this->assertSame(PccVerificationStatus::Rejected, $record->fresh()->verification_status);
        $this->assertSame(DocumentStatus::Rejected, $record->fresh()->document->status);
        $this->assertSame(PoliceClearanceStatus::Rejected, $applicant->applicantProfile->fresh()->policeClearanceStatus());
        Notification::assertSentTo($applicant, TemplatedNotification::class, fn ($n) => $n->templateKey === 'pcc.rejected');
        $this->actingAs($applicant)->get(route('applicant.police-clearance.index'))->assertSee('Name does not match NIC');
    }

    public function test_change_renewal_date_resets_reminders(): void
    {
        $officer = $this->makeStaff('Compliance Officer');
        $record = $this->upload($this->makeApplicant());
        $verify = app(PoliceClearanceVerification::class);
        $verify->approve($record, $officer, Carbon::parse('2027-10-20'));
        $record->reminders()->create(['reminder_type' => 'd30', 'sent_at' => now()]);

        try {
            $verify->changeRenewalDate($record, $officer, Carbon::parse('2027-12-01'), 'later than expiry');
            $this->fail('Must not exceed expiry');
        } catch (ValidationException) {
        }

        $verify->changeRenewalDate($record->fresh(), $officer, Carbon::parse('2027-03-01'), 'Client requires renewal before Q2 project');

        $this->assertSame('2027-03-01', $record->fresh()->next_renewal_due->toDateString());
        $this->assertSame(0, $record->reminders()->count());
        $this->assertTrue(AuditLog::where('action', 'pcc.renewal_date_changed')->exists());
    }

    public function test_eligibility_for_assignments(): void
    {
        $officer = $this->makeStaff('Compliance Officer');
        $applicant = $this->makeApplicant();
        $profile = fn () => $applicant->applicantProfile->fresh('currentPoliceClearance');

        $this->assertFalse(PoliceClearanceEligibility::isEligible($profile(), Carbon::parse('2026-12-01')));
        $this->assertSame('No verified police clearance certificate.', PoliceClearanceEligibility::reason($profile(), Carbon::parse('2026-12-01')));

        $record = $this->upload($applicant);
        $this->assertFalse(PoliceClearanceEligibility::isEligible($profile(), Carbon::parse('2026-12-01')), 'pending is never valid');

        app(PoliceClearanceVerification::class)->approve($record, $officer, Carbon::parse('2027-03-31'));
        $this->assertTrue(PoliceClearanceEligibility::isEligible($profile(), Carbon::parse('2027-03-31')));
        $this->assertFalse(PoliceClearanceEligibility::isEligible($profile(), Carbon::parse('2027-04-01')), 'must cover the whole assignment');
        $this->assertSame(1, PoliceClearanceEligibility::scope(\App\Models\ApplicantProfile::query(), Carbon::parse('2027-03-01'))->count());

        // Expiry makes them ineligible automatically — no batch job needed.
        Carbon::setTestNow('2027-04-02');
        $this->assertFalse(PoliceClearanceEligibility::isEligible($profile(), today()));
        $this->assertStringStartsWith('Police clearance expired', PoliceClearanceEligibility::reason($profile(), today()));
        $this->assertSame(0, PoliceClearanceEligibility::scope(\App\Models\ApplicantProfile::query(), today())->count());

        // Blacklisted applicants are never eligible.
        Carbon::setTestNow('2026-11-02 09:00');
        $profile()->forceFill(['applicant_status' => 'blacklisted'])->save();
        $this->assertFalse(PoliceClearanceEligibility::isEligible($profile(), Carbon::parse('2026-12-01')));
    }

    public function test_compliance_queue_actions_in_admin(): void
    {
        Notification::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $officer = $this->makeStaff('Compliance Officer');
        $pending = $this->upload($this->makeApplicant());
        $toReject = $this->upload($this->makeApplicant());

        $this->actingAs($officer)->get('/admin/police-clearance')->assertOk();

        Livewire::actingAs($officer)->test(ListPoliceClearances::class)
            ->assertCanSeeTableRecords([$pending, $toReject])
            ->callTableAction('approve', $pending, data: ['valid_until' => '2027-10-20', 'next_renewal_due' => null])
            ->assertHasNoTableActionErrors()
            ->callTableAction('reject', $toReject, data: ['reason' => 'Illegible scan'])
            ->assertHasNoTableActionErrors()
            ->assertCanNotSeeTableRecords([$pending, $toReject]); // default view: awaiting verification

        $this->assertSame(PccVerificationStatus::Approved, $pending->fresh()->verification_status);
        $this->assertSame(PccVerificationStatus::Rejected, $toReject->fresh()->verification_status);

        // Recruiters can't see the queue at all.
        $this->actingAs($this->makeStaff('Recruiter'))->get('/admin/police-clearance')->assertForbidden();
    }
}
