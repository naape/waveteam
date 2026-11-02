<?php

namespace Tests\Feature\Applicant;

use App\Enums\DocumentStatus;
use App\Enums\PccVerificationStatus;
use App\Enums\PoliceClearanceStatus;
use App\Models\ApplicantDocument;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\PoliceClearanceRecord;
use App\Models\User;
use App\Notifications\TemplatedNotification;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesApplicants;
use Tests\TestCase;

class DocumentsAndClearanceTest extends TestCase
{
    use CreatesApplicants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seed([RolesAndPermissionsSeeder::class, NotificationTemplateSeeder::class]);
    }

    private function uploadPcc(User $user, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->post(route('applicant.police-clearance.store'), array_merge([
            'issue_date' => now()->subMonth()->toDateString(),
            'certificate_number' => 'PHQ/2026/12345',
            'issuing_authority' => 'Police Headquarters',
            'file' => $this->fakePdf('pcc.pdf', 'police clearance'),
            'confirm_genuine' => '1',
        ], $overrides));
    }

    public function test_pages_render(): void
    {
        $user = $this->makeApplicant();
        $this->actingAs($user)->get(route('applicant.documents.index'))->assertOk();
        $this->actingAs($user)->get(route('applicant.police-clearance.index'))->assertOk()->assertSee('Not Submitted');
    }

    public function test_reuploading_a_cv_supersedes_but_keeps_history(): void
    {
        $user = $this->makeApplicant();
        $this->actingAs($user);

        $this->post(route('applicant.documents.store'), ['document_type' => 'cv', 'file' => $this->fakePdf('cv-2025.pdf')])->assertSessionHasNoErrors();
        $this->post(route('applicant.documents.store'), ['document_type' => 'cv', 'file' => $this->fakePdf('cv-2026.pdf')])->assertSessionHasNoErrors();

        $docs = $user->applicantProfile->documents()->where('document_type', 'cv')->orderBy('version')->get();
        $this->assertCount(2, $docs);
        $this->assertFalse($docs[0]->is_current);
        $this->assertSame(DocumentStatus::Superseded, $docs[0]->status);
        $this->assertSame($docs[1]->id, $docs[0]->superseded_by_id);
        $this->assertTrue($docs[1]->is_current);
        $this->assertSame(2, $docs[1]->version);

        // Certificates can have several current documents.
        $this->post(route('applicant.documents.store'), ['document_type' => 'education_cert', 'file' => $this->fakePdf('a.pdf')]);
        $this->post(route('applicant.documents.store'), ['document_type' => 'education_cert', 'file' => $this->fakePdf('b.pdf')]);
        $this->assertSame(2, $user->applicantProfile->documents()->where('document_type', 'education_cert')->where('is_current', true)->count());
    }

    public function test_invalid_upload_types_are_rejected(): void
    {
        $user = $this->makeApplicant();

        $this->actingAs($user)->post(route('applicant.documents.store'), [
            'document_type' => 'police_clearance', 'file' => $this->fakePdf(),
        ])->assertSessionHasErrors('document_type'); // PCC only via its own flow

        $this->actingAs($user)->post(route('applicant.documents.store'), [
            'document_type' => 'cv', 'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('cv.pdf', 'MZ executable'),
        ])->assertSessionHasErrors('file');
    }

    public function test_document_access_rules(): void
    {
        $owner = $this->makeApplicant();
        $this->actingAs($owner)->post(route('applicant.documents.store'), ['document_type' => 'cv', 'file' => $this->fakePdf()]);
        $this->uploadPcc($owner);
        $cv = ApplicantDocument::where('document_type', 'cv')->firstOrFail();
        $pcc = ApplicantDocument::where('document_type', 'police_clearance')->firstOrFail();

        // Owner can view and download, response is private and sandboxed.
        $res = $this->actingAs($owner)->get(route('documents.file', $cv));
        $res->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertStringContainsString('sandbox', $res->headers->get('Content-Security-Policy'));
        $this->assertStringStartsWith('%PDF', $res->streamedContent());
        $this->actingAs($owner)->get(route('documents.file', [$pcc, 'download' => 1]))->assertOk()->assertDownload('pcc.pdf');

        // Another applicant / an organisation contact: forbidden.
        $this->actingAs($this->makeApplicant())->get(route('documents.file', $cv))->assertForbidden();
        $this->actingAs(User::factory()->orgContact()->create())->get(route('documents.file', $cv))->assertForbidden();

        // Recruiter: CV yes, police clearance no.
        $recruiter = $this->makeStaff('Recruiter');
        $this->actingAs($recruiter)->get(route('documents.file', $cv))->assertOk();
        $this->actingAs($recruiter)->get(route('documents.file', $pcc))->assertForbidden();

        // Compliance officer: police clearance yes.
        $this->actingAs($this->makeStaff('Compliance Officer'))->get(route('documents.file', $pcc))->assertOk();

        // Staff without 2FA set up: blocked everywhere.
        $this->actingAs($this->makeStaff('Super Admin', mfa: false))->get(route('documents.file', $cv))->assertForbidden();

        // Every view/download is audited.
        $this->assertGreaterThanOrEqual(4, AuditLog::whereIn('action', ['document.viewed', 'document.downloaded'])->count());
    }

    public function test_police_clearance_upload_is_pending_and_notifies_verifiers(): void
    {
        Notification::fake();
        $compliance = $this->makeStaff('Compliance Officer');
        $recruiter = $this->makeStaff('Recruiter');
        $user = $this->makeApplicant();

        $this->uploadPcc($user)->assertSessionHasNoErrors()->assertRedirect(route('applicant.police-clearance.index'));

        $record = PoliceClearanceRecord::firstOrFail();
        $this->assertSame(PccVerificationStatus::PendingVerification, $record->verification_status);
        $this->assertNull($record->valid_until);
        $this->assertSame('PHQ/2026/12345', $record->certificate_number);
        $this->assertStringNotContainsString('PHQ/2026', \DB::table('police_clearance_records')->value('certificate_number'));
        $this->assertSame(PoliceClearanceStatus::PendingVerification, $user->applicantProfile->policeClearanceStatus());

        Notification::assertSentTo($compliance, TemplatedNotification::class, fn ($n) => $n->templateKey === 'pcc.uploaded.admin');
        Notification::assertNotSentTo($recruiter, TemplatedNotification::class);
        $this->assertTrue(AuditLog::where('action', 'pcc.uploaded')->exists());
    }

    public function test_rate_limits_are_per_action_not_shared(): void
    {
        // Regression: plain "throttle:N,1" shares one counter per user across
        // routes, so saving a few profile sections blocked the PCC upload.
        $user = $this->makeApplicant();
        for ($i = 0; $i < 8; $i++) {
            $this->actingAs($user)->post(route('applicant.profile.section.store', 'education'), ['qualification' => "Q{$i}", 'institution' => 'X']);
        }

        $this->uploadPcc($user)->assertSessionHasNoErrors()->assertRedirect(route('applicant.police-clearance.index'));
    }

    public function test_police_clearance_validation(): void
    {
        $user = $this->makeApplicant();

        $this->uploadPcc($user, ['issue_date' => now()->addDay()->toDateString()])->assertSessionHasErrors('issue_date');
        $this->uploadPcc($user, ['issue_date' => now()->subYears(4)->toDateString()])->assertSessionHasErrors('issue_date');
        $this->uploadPcc($user, ['confirm_genuine' => null])->assertSessionHasErrors('confirm_genuine');
        $this->assertSame(0, PoliceClearanceRecord::count());
    }

    public function test_new_upload_replaces_pending_but_keeps_approved_certificate_in_force(): void
    {
        $user = $this->makeApplicant();
        $profile = $user->applicantProfile;

        // An approved certificate already in force.
        $this->uploadPcc($user);
        $approved = PoliceClearanceRecord::firstOrFail();
        $approved->forceFill(['verification_status' => 'approved', 'valid_until' => now()->addMonths(2)])->save();
        $profile->forceFill(['current_police_clearance_id' => $approved->id])->save();

        // Two renewals uploaded; the first pending one is replaced by the second.
        $this->uploadPcc($user);
        $this->uploadPcc($user);

        $records = PoliceClearanceRecord::orderBy('id')->get();
        $this->assertCount(3, $records);
        $this->assertNull($records[0]->superseded_at, 'approved certificate stays in force');
        $this->assertNotNull($records[1]->superseded_at, 'older pending upload replaced');
        $this->assertNull($records[2]->superseded_at);
        $this->assertSame(1, PoliceClearanceRecord::pending()->whereNull('superseded_at')->count());

        // Still valid until a new one is approved.
        $this->assertSame(PoliceClearanceStatus::Valid, $profile->fresh()->policeClearanceStatus());
        $this->assertSame(3, ApplicantDocument::withTrashed()->where('document_type', 'police_clearance')->count());
    }
}
