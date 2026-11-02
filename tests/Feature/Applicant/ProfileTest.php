<?php

namespace Tests\Feature\Applicant;

use App\Models\AuditLog;
use App\Models\District;
use App\Models\JobCategory;
use App\Models\Skill;
use App\Support\Profile\ProfileCompletion;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesApplicants;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use CreatesApplicants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('documents');
    }

    private function personal(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Nimal Perera',
            'date_of_birth' => '1990-04-12',
            'gender' => 'male',
            'permanent_address' => '12 Temple Road, Gampaha',
            'current_same' => '1',
            'district_id' => District::where('name_en', 'Gampaha')->value('id'),
            'contact_number' => '0771234567',
            'whatsapp_same' => '1',
            'emergency_contact_name' => 'Kamala Perera',
            'emergency_contact_number' => '0711234567',
        ], $overrides);
    }

    public function test_all_profile_pages_render(): void
    {
        $user = $this->makeApplicant(['nic_number' => '199012345678']);
        $this->actingAs($user);

        $this->get(route('applicant.profile.personal'))->assertOk()->assertSee('199012345678');
        foreach (['education', 'certifications', 'employment', 'languages', 'driving-licences', 'military'] as $section) {
            $this->get(route('applicant.profile.section', $section))->assertOk();
        }
        $this->get(route('applicant.profile.skills'))->assertOk()->assertSee('Tower Climbing (Certified)');
        $this->get(route('applicant.profile.preferences'))->assertOk()->assertSee('Gampaha');
    }

    public function test_personal_details_are_saved_and_normalised(): void
    {
        $user = $this->makeApplicant(['nic_number' => '199012345678']);

        $this->actingAs($user)->put(route('applicant.profile.personal'), $this->personal(['photo' => UploadedFile::fake()->image('me.jpg', 300, 300)]))
            ->assertSessionHasNoErrors()->assertSessionHas('status');

        $p = $user->applicantProfile->fresh();
        $this->assertSame('+94771234567', $p->contact_number);
        $this->assertSame('+94771234567', $p->whatsapp_number);
        $this->assertSame($p->permanent_address, $p->current_address);
        $this->assertNotNull($p->photo_file_id);
        $this->assertTrue(AuditLog::where('action', 'applicant.profile_updated')->exists());
    }

    public function test_date_of_birth_must_match_nic_birth_year(): void
    {
        $user = $this->makeApplicant(['nic_number' => '199012345678']);

        $this->actingAs($user)->put(route('applicant.profile.personal'), $this->personal(['date_of_birth' => '1985-01-01']))
            ->assertSessionHasErrors('date_of_birth');
    }

    public function test_nic_cannot_be_changed_by_applicant(): void
    {
        $user = $this->makeApplicant(['nic_number' => '199012345678']);

        $this->actingAs($user)->put(route('applicant.profile.personal'), $this->personal(['nic_number' => '200012345678']));

        $this->assertSame('199012345678', $user->applicantProfile->fresh()->nic_number);
    }

    public function test_repeatable_section_crud(): void
    {
        $user = $this->makeApplicant();
        $this->actingAs($user);

        $this->post(route('applicant.profile.section.store', 'education'), [
            'qualification' => 'NVQ Level 4 Electrician', 'institution' => 'VTA Gampaha', 'year_completed' => 2012,
        ])->assertSessionHasNoErrors();

        $entry = $user->applicantProfile->educations()->firstOrFail();

        $this->put(route('applicant.profile.section.update', ['education', $entry->id]), [
            'qualification' => 'NVQ Level 5 Electrician', 'institution' => 'VTA Gampaha', 'year_completed' => 2014,
        ])->assertRedirect(route('applicant.profile.section', 'education'));
        $this->assertSame('NVQ Level 5 Electrician', $entry->fresh()->qualification);

        $this->delete(route('applicant.profile.section.destroy', ['education', $entry->id]));
        $this->assertSame(0, $user->applicantProfile->educations()->count());
    }

    public function test_cannot_touch_another_applicants_entries(): void
    {
        $owner = $this->makeApplicant();
        $entry = $owner->applicantProfile->employments()->create(['employer' => 'Dialog', 'designation' => 'Rigger', 'start_date' => '2018-01-01']);

        $intruder = $this->makeApplicant();
        $this->actingAs($intruder);

        $this->get(route('applicant.profile.section', ['section' => 'employment', 'edit' => $entry->id]))->assertNotFound();
        $this->put(route('applicant.profile.section.update', ['employment', $entry->id]), ['employer' => 'Hacked', 'designation' => 'x', 'start_date' => '2018-01-01'])->assertNotFound();
        $this->delete(route('applicant.profile.section.destroy', ['employment', $entry->id]))->assertNotFound();

        $this->assertSame('Dialog', $entry->fresh()->employer);
    }

    public function test_section_validation_and_uniqueness(): void
    {
        $user = $this->makeApplicant();
        $this->actingAs($user);

        $this->post(route('applicant.profile.section.store', 'employment'), [
            'employer' => 'X', 'designation' => 'Y', 'start_date' => '2020-01-01', 'end_date' => '2019-01-01',
        ])->assertSessionHasErrors('end_date');

        $lang = ['language' => 'English', 'speak' => 'good', 'read' => 'good', 'write' => 'basic'];
        $this->post(route('applicant.profile.section.store', 'languages'), $lang)->assertSessionHasNoErrors();
        $this->post(route('applicant.profile.section.store', 'languages'), $lang)->assertSessionHasErrors('language');

        $this->post(route('applicant.profile.section.store', 'driving-licences'), [
            'licence_number' => 'B1234567', 'vehicle_classes' => ['B', 'NOT-A-CLASS'],
        ])->assertSessionHasErrors('vehicle_classes.1');
    }

    public function test_driving_licence_number_is_encrypted(): void
    {
        $user = $this->makeApplicant();
        $this->actingAs($user)->post(route('applicant.profile.section.store', 'driving-licences'), [
            'licence_number' => 'B9876543', 'vehicle_classes' => ['B1', 'B'],
        ])->assertSessionHasNoErrors();

        $raw = \DB::table('applicant_driving_licences')->value('licence_number');
        $this->assertStringNotContainsString('B9876543', $raw);
        $this->assertSame(['B1', 'B'], $user->applicantProfile->drivingLicences()->first()->vehicle_classes);
    }

    public function test_skills_and_preferences(): void
    {
        $user = $this->makeApplicant();
        $this->actingAs($user);
        $cat = JobCategory::where('slug', 'tower-construction-and-maintenance')->first();
        $skill = Skill::where('name', 'Rigging')->first();

        $this->put(route('applicant.profile.skills'), [
            'job_categories' => [$cat->id],
            'total_experience_years' => 4,
            'skills' => [
                $skill->id => ['selected' => '1', 'years' => 3, 'level' => 'expert'],
                999999 => ['selected' => '1', 'years' => 1], // bogus id ignored
            ],
        ])->assertSessionHasNoErrors();

        $p = $user->applicantProfile->fresh();
        $this->assertSame([$skill->id], $p->skills->pluck('id')->all());
        $this->assertSame('expert', $p->skills->first()->pivot->level);
        $this->assertEquals(4, $p->total_experience_years);

        $this->put(route('applicant.profile.preferences'), [
            'districts' => [District::first()->id],
            'availability_status' => 'available_from',
            'available_from' => now()->addWeek()->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->assertSame('available_from', $p->fresh()->availability_status->value);

        $this->put(route('applicant.profile.preferences'), ['districts' => [], 'availability_status' => 'engaged'])
            ->assertSessionHasErrors(['districts', 'availability_status']);
    }

    public function test_profile_completion_score(): void
    {
        $user = $this->makeApplicant(['nic_number' => '199012345678']);
        $this->actingAs($user);
        $this->assertSame(0, ProfileCompletion::score($user->applicantProfile));

        $this->put(route('applicant.profile.personal'), $this->personal());
        $this->assertSame(20, $user->applicantProfile->fresh()->profile_completion);

        $this->post(route('applicant.documents.store'), ['document_type' => 'cv', 'file' => $this->fakePdf()]);
        $this->assertSame(30, $user->applicantProfile->fresh()->profile_completion);
    }

    public function test_engaged_status_cannot_be_overridden_by_applicant(): void
    {
        $user = $this->makeApplicant(['availability_status' => 'engaged']);

        $this->actingAs($user)->put(route('applicant.profile.preferences'), [
            'districts' => [District::first()->id], 'availability_status' => 'available',
        ]);

        $this->assertSame('engaged', $user->applicantProfile->fresh()->availability_status->value);
    }
}
