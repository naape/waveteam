<?php

namespace Tests\Feature\Admin;

use App\Enums\PccVerificationStatus;
use App\Enums\PoliceClearanceStatus;
use App\Filament\Resources\Applicants\Pages\ViewApplicant;
use App\Filament\Resources\Applicants\RelationManagers\DrivingLicencesRelationManager;
use App\Filament\Resources\Applicants\RelationManagers\EducationRelationManager;
use App\Filament\Resources\Applicants\RelationManagers\LanguagesRelationManager;
use App\Models\ApplicantEducation;
use App\Models\ApplicantProfile;
use App\Models\AuditLog;
use App\Models\PoliceClearanceRecord;
use App\Support\Compliance\PoliceClearanceEligibility;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\CreatesApplicants;
use Tests\TestCase;

class ApplicantRecordsEditTest extends TestCase
{
    use CreatesApplicants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Notification::fake();
        $this->seed([ReferenceDataSeeder::class, RolesAndPermissionsSeeder::class, NotificationTemplateSeeder::class]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function profile(): ApplicantProfile
    {
        return $this->makeApplicant(['nic_number' => '199012345678'])->applicantProfile;
    }

    private function section(string $class, ApplicantProfile $p)
    {
        return Livewire::test($class, ['ownerRecord' => $p, 'pageClass' => ViewApplicant::class]);
    }

    // ---- Education & other sections ---------------------------------------------

    public function test_add_edit_and_remove_education(): void
    {
        $p = $this->profile();
        $this->actingAs($this->makeStaff('HR Manager'));

        $this->section(EducationRelationManager::class, $p)
            ->callAction(TestAction::make(CreateAction::class)->table(), data: [
                'qualification' => 'NVQ Level 4 Electrician', 'institution' => 'VTA Colombo', 'year_completed' => 2015, 'result_grade' => 'Pass',
            ])
            ->assertHasNoActionErrors();

        $edu = ApplicantEducation::where('applicant_profile_id', $p->id)->firstOrFail();
        $this->assertSame('VTA Colombo', $edu->institution);
        $this->assertTrue(AuditLog::where('action', 'applicant.profile_entry_added')->where('meta->by_staff', true)->exists());

        $this->section(EducationRelationManager::class, $p)
            ->callAction(TestAction::make(EditAction::class)->table($edu), data: ['institution' => 'VTA Kandy', 'year_completed' => 2016])
            ->assertHasNoActionErrors();
        $this->assertSame('VTA Kandy', $edu->fresh()->institution);

        $this->section(EducationRelationManager::class, $p)
            ->callAction(TestAction::make('remove')->table($edu));
        $this->assertModelMissing($edu);
    }

    public function test_section_rules_are_enforced(): void
    {
        $p = $this->profile();
        $this->actingAs($this->makeStaff('HR Manager'));

        $this->section(EducationRelationManager::class, $p)
            ->callAction(TestAction::make(CreateAction::class)->table(), data: ['qualification' => '', 'institution' => 'X', 'year_completed' => 1800])
            ->assertHasActionErrors(['qualification', 'year_completed']);

        // A language can only be listed once per applicant.
        $lang = ['language' => 'English', 'speak' => 'good', 'read' => 'good', 'write' => 'basic'];
        $this->section(LanguagesRelationManager::class, $p)->callAction(TestAction::make(CreateAction::class)->table(), data: $lang)->assertHasNoActionErrors();
        $this->section(LanguagesRelationManager::class, $p)->callAction(TestAction::make(CreateAction::class)->table(), data: $lang)->assertHasActionErrors(['language']);
    }

    public function test_view_only_roles_and_pii_sections_are_read_only(): void
    {
        $p = $this->profile();

        $this->actingAs($this->makeStaff('Compliance Officer')); // view, no update
        $this->section(EducationRelationManager::class, $p)->assertActionHidden(TestAction::make(CreateAction::class)->table());

        $this->actingAs($this->makeStaff('Recruiter')); // update, but no PII → licence numbers stay read-only
        $this->section(EducationRelationManager::class, $p)->assertActionVisible(TestAction::make(CreateAction::class)->table());
        $this->section(DrivingLicencesRelationManager::class, $p)->assertActionHidden(TestAction::make(CreateAction::class)->table());
    }

    public function test_applicant_page_still_renders_with_sections(): void
    {
        $p = $this->profile();
        $this->actingAs($this->makeStaff('Super Admin'));
        $this->get("/admin/applicants/{$p->id}")->assertOk()->assertSee('Education')->assertSee('Police clearance');
    }

    // ---- Skills, job categories & preferences --------------------------------------

    public function test_staff_edit_job_categories_experience_and_skills(): void
    {
        $p = $this->profile();
        [$catA, $catB] = \App\Models\JobCategory::where('is_active', true)->has('skills')->take(2)->get()->all();
        $skillA = $catA->skills()->first();
        $skillB = $catB->skills()->first();
        $this->actingAs($this->makeStaff('HR Manager'));

        Livewire::test(ViewApplicant::class, ['record' => $p->getRouteKey()])
            ->callAction('editSkills', data: [
                'job_categories' => [$catA->id, $catB->id],
                'total_experience_years' => 7.5,
                'skills' => [
                    ['skill_id' => $skillA->id, 'years' => 5, 'level' => 'expert'],
                    ['skill_id' => $skillB->id, 'years' => 2, 'level' => 'basic'],
                ],
                'reason' => 'Checked at interview',
            ])
            ->assertHasNoActionErrors();

        $p->refresh();
        $this->assertEqualsCanonicalizing([$catA->id, $catB->id], $p->jobCategories()->pluck('job_categories.id')->all());
        $this->assertEquals(7.5, (float) $p->total_experience_years);
        $this->assertSame('expert', $p->skills()->find($skillA->id)->pivot->level);
        $this->assertEquals(2, (float) $p->skills()->find($skillB->id)->pivot->years);
        $log = AuditLog::where('action', 'applicant.skills_updated_by_staff')->firstOrFail();
        $this->assertSame('Checked at interview', $log->meta['reason']);

        // At least one category is required; a skill can't be listed twice.
        Livewire::test(ViewApplicant::class, ['record' => $p->getRouteKey()])
            ->callAction('editSkills', data: ['job_categories' => [], 'total_experience_years' => 1, 'skills' => [], 'reason' => 'x'])
            ->assertHasActionErrors(['job_categories']);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Actions\Admin\ApplicantRecordsActions::class)->updateSkills($p, [
            'job_categories' => [$catA->id], 'total_experience_years' => 1,
            'skills' => [['skill_id' => $skillA->id], ['skill_id' => $skillA->id]],
        ], $this->makeStaff('Super Admin'), 'x');
    }

    public function test_staff_edit_work_preferences(): void
    {
        $p = $this->profile();
        $districts = \App\Models\District::take(3)->pluck('id')->all();
        $this->actingAs($this->makeStaff('Recruiter'));

        Livewire::test(ViewApplicant::class, ['record' => $p->getRouteKey()])
            ->callAction('editPreferences', data: [
                'districts' => $districts,
                'availability_status' => 'available_from',
                'available_from' => now()->addWeeks(2)->toDateString(),
                'reason' => 'Applicant called',
            ])
            ->assertHasNoActionErrors();

        $p->refresh();
        $this->assertEqualsCanonicalizing($districts, $p->preferredDistricts()->pluck('districts.id')->all());
        $this->assertSame(\App\Enums\AvailabilityStatus::AvailableFrom, $p->availability_status);
        $this->assertSame(now()->addWeeks(2)->toDateString(), $p->available_from->toDateString());
        $this->assertTrue(AuditLog::where('action', 'applicant.preferences_updated_by_staff')->exists());
    }

    public function test_engaged_availability_cannot_be_changed_by_staff(): void
    {
        $p = $this->profile();
        $p->forceFill(['availability_status' => \App\Enums\AvailabilityStatus::Engaged])->save();
        $this->actingAs($this->makeStaff('HR Manager'));

        app(\App\Actions\Admin\ApplicantRecordsActions::class)->updatePreferences($p, [
            'districts' => [\App\Models\District::first()->id], 'availability_status' => 'available',
        ], $this->makeStaff('HR Manager'), 'test');

        $p->refresh();
        $this->assertSame(\App\Enums\AvailabilityStatus::Engaged, $p->availability_status);
        $this->assertCount(1, $p->preferredDistricts);
    }

    public function test_view_only_roles_cannot_edit_skills_or_preferences(): void
    {
        $p = $this->profile();
        $this->actingAs($this->makeStaff('Compliance Officer'));
        Livewire::test(ViewApplicant::class, ['record' => $p->getRouteKey()])
            ->assertActionHidden('editSkills')
            ->assertActionHidden('editPreferences')
            ->assertActionHidden('editDetails');
    }

    // ---- Police clearance ---------------------------------------------------------

    public function test_staff_upload_stays_pending_until_verified(): void
    {
        $p = $this->profile();
        $this->actingAs($this->makeStaff('Recruiter')); // can upload, cannot verify

        Livewire::test(ViewApplicant::class, ['record' => $p->getRouteKey()])
            ->assertActionHidden('removePcc')
            ->callAction('uploadPcc', data: ['file' => $this->fakePdf('pcc.pdf'), 'issue_date' => now()->subMonth()->toDateString()])
            ->assertHasNoActionErrors();

        $record = PoliceClearanceRecord::where('applicant_profile_id', $p->id)->firstOrFail();
        $this->assertSame(PccVerificationStatus::PendingVerification, $record->verification_status);
        $this->assertSame(PoliceClearanceStatus::PendingVerification, $p->fresh()->policeClearanceStatus());
        $this->assertTrue(AuditLog::where('action', 'pcc.uploaded_by_staff')->exists());
    }

    public function test_verifier_can_upload_and_approve_in_one_step(): void
    {
        $p = $this->profile();
        $this->actingAs($this->makeStaff('Compliance Officer'));

        Livewire::test(ViewApplicant::class, ['record' => $p->getRouteKey()])
            ->callAction('uploadPcc', data: [
                'file' => $this->fakePdf('pcc.pdf'), 'issue_date' => now()->subMonth()->toDateString(),
                'approve_now' => true, 'valid_until' => now()->addMonths(11)->toDateString(),
            ])
            ->assertHasNoActionErrors();

        $p->refresh();
        $this->assertSame(PoliceClearanceStatus::Valid, $p->policeClearanceStatus());
        $this->assertNotNull($p->current_police_clearance_id);
    }

    public function test_removing_the_certificate_in_force_keeps_history_and_makes_applicant_ineligible(): void
    {
        $p = $this->profile();
        $officer = $this->makeStaff('Compliance Officer');
        $this->actingAs($officer);
        $record = app(\App\Actions\Admin\ApplicantRecordsActions::class)->uploadPoliceClearance(
            $p, $this->fakePdf('pcc.pdf'), now()->subMonth(), $officer, approve: ['valid_until' => now()->addMonths(11)]);
        $this->assertSame(PoliceClearanceStatus::Valid, $p->fresh()->policeClearanceStatus());

        Livewire::test(ViewApplicant::class, ['record' => $p->getRouteKey()])
            ->callAction('removePcc', data: ['record_id' => $record->id, 'reason' => 'Uploaded to the wrong applicant'])
            ->assertHasNoActionErrors();

        $record->refresh();
        $p->refresh();
        $this->assertNotNull($record->removed_at);                    // kept, not deleted
        $this->assertSame('Uploaded to the wrong applicant', $record->removal_reason);
        $this->assertSame($officer->id, $record->removed_by);
        $this->assertNull($p->current_police_clearance_id);
        $this->assertSame(PoliceClearanceStatus::Missing, $p->policeClearanceStatus());
        $this->assertTrue(ApplicantProfile::wherePccStatus(PoliceClearanceStatus::Missing)->whereKey($p->id)->exists());
        $this->assertFalse(PoliceClearanceEligibility::isEligible($p, today()->addWeek()));
        $this->assertDatabaseHas('police_clearance_records', ['id' => $record->id]);
        $this->assertNotNull($record->document?->file); // file record kept in the vault
        $this->assertTrue(AuditLog::where('action', 'pcc.removed')->exists());
    }

    public function test_removal_requires_a_reason(): void
    {
        $p = $this->profile();
        $this->actingAs($this->makeStaff('Compliance Officer'));
        Livewire::test(ViewApplicant::class, ['record' => $p->getRouteKey()])
            ->callAction('uploadPcc', data: ['file' => $this->fakePdf('pcc.pdf'), 'issue_date' => now()->subWeek()->toDateString()]);
        $record = PoliceClearanceRecord::firstOrFail();

        Livewire::test(ViewApplicant::class, ['record' => $p->getRouteKey()])
            ->callAction('removePcc', data: ['record_id' => $record->id, 'reason' => ''])
            ->assertHasActionErrors(['reason']);
        $this->assertNull($record->fresh()->removed_at);
    }
}
