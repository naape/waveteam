<?php

namespace Tests\Feature\Workforce;

use App\Actions\Workforce\AssignmentActions;
use App\Enums\AssignmentStatus;
use App\Enums\AvailabilityStatus;
use App\Enums\ServiceRequestStatus;
use App\Filament\Resources\Assignments\Pages\ListAssignments;
use App\Filament\Resources\ServiceRequests\Pages\MatchWorkforce;
use App\Models\ApplicantDocument;
use App\Models\ApplicantProfile;
use App\Models\District;
use App\Models\JobCategory;
use App\Models\PoliceClearanceRecord;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\Skill;
use App\Models\StoredFile;
use App\Models\User;
use App\Models\WorkforceAssignment;
use App\Notifications\TemplatedNotification;
use App\Support\Settings;
use App\Support\Workforce\WorkforceMatcher;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesApplicants;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class WorkforceTest extends TestCase
{
    use CreatesApplicants, CreatesOrganizations, RefreshDatabase;

    private ServiceRequest $request;

    private User $ops;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00');
        Notification::fake();
        $this->seed([ReferenceDataSeeder::class, RolesAndPermissionsSeeder::class, NotificationTemplateSeeder::class]);
        $this->ops = $this->makeStaff('Operations Manager');

        // Tower job in Gampaha, 1 Dec – 31 Dec 2026, rigging 2+ yrs, clearance required.
        $this->request = $this->makeServiceRequest($this->makeOrgContact(), ServiceRequestStatus::Approved, [
            'service_category_id' => ServiceCategory::where('slug', 'tower-construction-and-maintenance')->value('id'),
            'district_id' => District::where('name_en', 'Gampaha')->value('id'),
            'start_date' => '2026-12-01', 'expected_duration_value' => 30, 'expected_duration_unit' => 'days',
            'required_experience_years' => 3, 'requires_police_clearance' => true,
        ]);
        $this->request->skills()->attach(Skill::where('name', 'Rigging')->value('id'), ['min_years' => 2]);
    }

    /** A fully eligible tower rigger; override any attribute to break one rule. */
    private function candidate(array $profile = [], array $opts = []): ApplicantProfile
    {
        $user = $this->makeApplicant(array_merge([
            'verification_status' => 'verified', 'total_experience_years' => 5, 'profile_completion' => 80,
            'district_id' => District::where('name_en', 'Colombo')->value('id'),
        ], $profile));
        $p = $user->applicantProfile;
        $p->jobCategories()->attach(JobCategory::where('slug', $opts['category'] ?? 'tower-construction-and-maintenance')->value('id'));
        $p->skills()->attach(Skill::where('name', 'Rigging')->value('id'), ['years' => $opts['rigging_years'] ?? 4, 'level' => 'expert']);
        if ($opts['prefers_gampaha'] ?? false) {
            $p->preferredDistricts()->attach(District::where('name_en', 'Gampaha')->value('id'));
        }

        $pccUntil = array_key_exists('pcc_until', $opts) ? $opts['pcc_until'] : '2027-06-30';
        if ($pccUntil) {
            $file = StoredFile::create(['uuid' => (string) Str::uuid(), 'disk' => 'documents', 'path' => 'x', 'original_name' => 'p.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1, 'sha256' => str_repeat('0', 64)]);
            $doc = ApplicantDocument::create(['applicant_profile_id' => $p->id, 'document_type' => 'police_clearance', 'stored_file_id' => $file->id]);
            $rec = PoliceClearanceRecord::forceCreate(['applicant_profile_id' => $p->id, 'applicant_document_id' => $doc->id, 'issue_date' => '2026-06-01', 'uploaded_at' => now(), 'verification_status' => 'approved', 'valid_until' => $pccUntil]);
            $p->forceFill(['current_police_clearance_id' => $rec->id])->save();
        }

        return $p->fresh();
    }

    private function matchIds(array $options = []): array
    {
        return app(WorkforceMatcher::class)->match($this->request->fresh(), $options)->map(fn ($m) => $m->profile->id)->all();
    }

    // ---- Matching --------------------------------------------------------------

    public function test_hard_filters(): void
    {
        $good = $this->candidate();
        $unverified = $this->candidate(['verification_status' => 'pending']);
        $blacklisted = $this->candidate(['applicant_status' => 'blacklisted']);
        $wrongTrade = $this->candidate(opts: ['category' => 'drivers-and-transportation']);
        $unavailable = $this->candidate(['availability_status' => 'unavailable']);
        $laterStart = $this->candidate(['availability_status' => 'available_from', 'available_from' => '2026-12-15']);
        $earlierStart = $this->candidate(['availability_status' => 'available_from', 'available_from' => '2026-11-20']);
        $noPcc = $this->candidate(opts: ['pcc_until' => null]);
        $pccLapses = $this->candidate(opts: ['pcc_until' => '2026-12-15']); // mid-assignment
        $junior = $this->candidate(['total_experience_years' => 1]);
        $busy = $this->candidate();
        $other = $this->makeServiceRequest($this->makeOrgContact(), ServiceRequestStatus::InProgress);
        WorkforceAssignment::forceCreate(['service_request_id' => $other->id, 'applicant_profile_id' => $busy->id, 'role_title' => 'Rigger', 'start_date' => '2026-12-20', 'end_date' => '2027-01-10', 'status' => 'confirmed']);
        $pending = $this->candidate();
        PoliceClearanceRecord::where('id', $pending->current_police_clearance_id)->update(['verification_status' => 'pending_verification']);

        $ids = $this->matchIds();

        $this->assertEqualsCanonicalizing([$good->id, $earlierStart->id], $ids);

        // Relaxing experience brings the junior back; clearance can't be relaxed.
        $this->assertContains($junior->id, $this->matchIds(['strict_experience' => false]));

        // Without a clearance requirement, people without it are fine.
        $this->request->update(['requires_police_clearance' => false]);
        $this->assertContains($noPcc->id, $this->matchIds());
        $this->assertContains($pccLapses->id, $this->matchIds());
        $this->assertNotContains($unverified->id, $this->matchIds(), 'verification is always required');
    }

    public function test_ranking_and_explanations(): void
    {
        $best = $this->candidate(['total_experience_years' => 8, 'profile_completion' => 100], ['prefers_gampaha' => true, 'rigging_years' => 6]);
        $weakSkill = $this->candidate(['total_experience_years' => 4], ['rigging_years' => 1]); // below min 2 years
        $best->certifications()->create(['kind' => 'training', 'title' => 'Tower climbing']);
        $best->educations()->create(['qualification' => 'NVQ 4', 'institution' => 'VTA']);

        $results = app(WorkforceMatcher::class)->match($this->request->fresh());

        $this->assertSame([$best->id, $weakSkill->id], $results->map(fn ($m) => $m->profile->id)->all());
        $top = $results->first();
        $this->assertSame(1.0, $top->breakdown['skills']);
        $this->assertSame(1.0, $top->breakdown['location']);
        $this->assertSame(100, $top->score);
        $this->assertStringContainsString('Skills: 1 of 1 required (Rigging)', implode("\n", $top->reasons));
        $this->assertStringContainsString('willing to work in Gampaha', implode("\n", $top->reasons));
        $this->assertStringContainsString('Police clearance valid until 30 Jun 2027', implode("\n", $top->reasons));
        $this->assertSame(0.5, $results->last()->breakdown['skills']);
        $this->assertStringContainsString('below 2 yrs', implode("\n", $results->last()->reasons));

        // Weights come from settings.
        Settings::set('matching.weights', ['skills' => 0, 'experience' => 0, 'qualifications' => 0, 'location' => 0, 'completeness' => 100]);
        $this->assertSame(80, app(WorkforceMatcher::class)->match($this->request->fresh())->firstWhere('profile.id', $weakSkill->id)->score);
    }

    // ---- Assigning -------------------------------------------------------------

    private function assign(ApplicantProfile $p, ?User $by = null, ?string $override = null): WorkforceAssignment
    {
        return app(AssignmentActions::class)->assign($this->request->fresh(), $p, 'Rigger', Carbon::parse('2026-12-01'), Carbon::parse('2026-12-31'), $by ?? $this->ops, $override);
    }

    public function test_assignment_rules(): void
    {
        $good = $this->candidate();
        $a = $this->assign($good);

        $this->assertSame(AssignmentStatus::Proposed, $a->status);
        $this->assertSame($good->current_police_clearance_id, $a->police_clearance_record_id, 'certificate snapshot');
        Notification::assertSentTo($good->user, TemplatedNotification::class, fn ($n) => $n->templateKey === 'assignment.created');

        $expect = function (callable $fn, string $msg) {
            try {
                $fn();
                $this->fail($msg);
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        };

        $expect(fn () => $this->assign($good), 'clash with itself');
        $expect(fn () => $this->assign($this->candidate(['verification_status' => 'pending'])), 'unverified');
        $expect(fn () => $this->assign($this->candidate(opts: ['pcc_until' => '2026-12-10'])), 'clearance lapses');
        $expect(fn () => $this->assign($this->candidate(), $this->makeStaff('Recruiter')), 'no permission');
        $expect(fn () => $this->assign($this->candidate(opts: ['pcc_until' => null]), $this->ops, 'urgent'), 'ops cannot override');

        $this->request->forceFill(['status' => ServiceRequestStatus::UnderReview])->save();
        $expect(fn () => $this->assign($this->candidate()), 'request not approved');

        $this->assertSame(1, WorkforceAssignment::count());
    }

    public function test_super_admin_override_is_recorded(): void
    {
        $noPcc = $this->candidate(opts: ['pcc_until' => null]);
        $a = $this->assign($noPcc, $this->makeStaff('Super Admin'), 'Client emergency; clearance applied for, ref PHQ/123');

        $this->assertTrue($a->pcc_override);
        $this->assertSame('Client emergency; clearance applied for, ref PHQ/123', $a->pcc_override_reason);
        $this->assertTrue(\App\Models\AuditLog::where('action', 'assignment.created')->whereJsonContains('meta->pcc_override_reason', 'Client emergency; clearance applied for, ref PHQ/123')->exists());
    }

    public function test_lifecycle_updates_request_and_availability(): void
    {
        $p = $this->candidate();
        $a = $this->assign($p);
        $actions = app(AssignmentActions::class);

        $actions->transition($a, AssignmentStatus::Confirmed, $this->ops);
        $this->assertSame(ServiceRequestStatus::TeamAssigned, $this->request->fresh()->status);

        $actions->transition($a->fresh(), AssignmentStatus::Active, $this->ops);
        $this->assertSame(ServiceRequestStatus::InProgress, $this->request->fresh()->status);
        $this->assertSame(AvailabilityStatus::Engaged, $p->fresh()->availability_status);

        try {
            $actions->transition($a->fresh(), AssignmentStatus::Proposed, $this->ops);
            $this->fail('no going back');
        } catch (ValidationException) {
        }

        $actions->transition($a->fresh(), AssignmentStatus::Completed, $this->ops);
        $this->assertSame(AvailabilityStatus::Available, $p->fresh()->availability_status);
        Notification::assertSentTo($p->user, TemplatedNotification::class, fn ($n) => $n->templateKey === 'assignment.status_changed' && $n->data['status'] === 'Completed');
    }

    public function test_release_needs_reason_and_start_rechecks_clearance(): void
    {
        $p = $this->candidate();
        $a = $this->assign($p);
        $actions = app(AssignmentActions::class);
        $actions->transition($a, AssignmentStatus::Confirmed, $this->ops);

        try {
            $actions->transition($a->fresh(), AssignmentStatus::Released, $this->ops);
            $this->fail('reason required');
        } catch (ValidationException) {
        }

        // Clearance expires before the team goes on site → cannot start.
        PoliceClearanceRecord::whereKey($p->current_police_clearance_id)->update(['valid_until' => '2026-11-30']);
        Carbon::setTestNow('2026-12-01');
        try {
            $actions->transition($a->fresh(), AssignmentStatus::Active, $this->ops);
            $this->fail('expired clearance must block start');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('expired', strtolower(implode(' ', $e->errors()['status'])));
        }

        $actions->transition($a->fresh(), AssignmentStatus::Released, $this->ops, 'Clearance lapsed');
        $this->assertSame(AssignmentStatus::Released, $a->fresh()->status);
        $this->assertNotContains($p->id, $this->matchIds(), 'still ineligible');
    }

    public function test_applicant_and_client_see_the_assignment(): void
    {
        $p = $this->candidate(['full_name' => 'Ruwan Jayasinghe']);
        $a = $this->assign($p);
        app(AssignmentActions::class)->transition($a, AssignmentStatus::Confirmed, $this->ops);

        $this->actingAs($p->user)->get(route('applicant.dashboard'))->assertSee('My assignments')->assertSee($this->request->title);

        $contact = $this->request->organization->users->first();
        $this->actingAs($contact)->get(route('organization.requests.show', $this->request))->assertDontSee('Ruwan Jayasinghe');
        $this->request->forceFill(['show_team_to_client' => true])->save();
        $this->actingAs($contact)->get(route('organization.requests.show', $this->request))->assertSee('Ruwan Jayasinghe');
    }

    // ---- Admin UI --------------------------------------------------------------

    public function test_matching_page_and_assign_action(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $p = $this->candidate(['full_name' => 'Nimal Bandara']);
        $this->actingAs($this->ops);

        $this->get("/admin/service-requests/{$this->request->reference}/matches")->assertOk()->assertSee('Nimal Bandara')->assertSee('Rigging');

        Livewire::test(MatchWorkforce::class, ['record' => $this->request->reference])
            ->callAction('assign', data: ['role_title' => 'Lead rigger', 'start_date' => '2026-12-01', 'end_date' => '2026-12-31'], arguments: ['profile' => $p->id])
            ->assertHasNoActionErrors();

        $this->assertSame('Lead rigger', WorkforceAssignment::firstOrFail()->role_title);

        Livewire::test(ListAssignments::class)
            ->callTableAction('to_confirmed', WorkforceAssignment::first(), data: ['note' => null])
            ->assertHasNoTableActionErrors();
        $this->assertSame(AssignmentStatus::Confirmed, WorkforceAssignment::first()->status);

        // Finance can't use workforce screens.
        $this->actingAs($this->makeStaff('Finance'))->get("/admin/service-requests/{$this->request->reference}/matches")->assertForbidden();
        $this->actingAs($this->makeStaff('Finance'))->get('/admin/assignments')->assertForbidden();
    }
}
