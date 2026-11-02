<?php

namespace Tests\Feature\Applicant;

use App\Enums\ApplicationStatus;
use App\Models\District;
use App\Models\JobApplication;
use App\Models\JobCategory;
use App\Models\JobVacancy;
use App\Notifications\TemplatedNotification;
use Database\Seeders\DemoVacancySeeder;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesApplicants;
use Tests\TestCase;

class VacancyApplicationTest extends TestCase
{
    use CreatesApplicants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seed([ReferenceDataSeeder::class, NotificationTemplateSeeder::class, DemoVacancySeeder::class]);
    }

    private function applicantWithCv()
    {
        $user = $this->makeApplicant();
        $this->actingAs($user)->post(route('applicant.documents.store'), ['document_type' => 'cv', 'file' => $this->fakePdf()]);

        return $user;
    }

    public function test_vacancies_can_be_searched_and_filtered(): void
    {
        $user = $this->makeApplicant();
        $this->actingAs($user);

        $this->get(route('applicant.vacancies.index'))->assertOk()->assertSee('Certified Tower Rigger')->assertSee('Fibre Splicing Technician');

        $this->get(route('applicant.vacancies.index', ['q' => 'fibre']))
            ->assertSee('Fibre Splicing Technician')->assertDontSee('Certified Tower Rigger');

        $this->get(route('applicant.vacancies.index', ['category' => JobCategory::where('slug', 'generator-services')->value('id')]))
            ->assertSee('Generator Mechanic')->assertDontSee('Fibre Splicing Technician');

        $this->get(route('applicant.vacancies.index', ['district' => District::where('name_en', 'Kandy')->value('id')]))
            ->assertSee('Fibre Splicing Technician')->assertDontSee('Generator Mechanic');

        // LIKE wildcards in the search term are treated literally.
        $this->get(route('applicant.vacancies.index', ['q' => '%']))->assertSee('0 vacancies found');
    }

    public function test_draft_and_closed_vacancies_are_hidden(): void
    {
        $user = $this->makeApplicant();
        $vacancy = JobVacancy::where('slug', 'generator-mechanic')->first();
        $vacancy->update(['status' => 'draft']);

        $this->actingAs($user)->get(route('applicant.vacancies.index'))->assertDontSee('Generator Mechanic');
        $this->actingAs($user)->get(route('applicant.vacancies.show', $vacancy))->assertNotFound();

        $vacancy->update(['status' => 'published', 'closing_date' => now()->subDay()]);
        $this->actingAs($user)->get(route('applicant.vacancies.show', $vacancy))->assertNotFound();
    }

    public function test_applicant_can_apply_once_with_cv_snapshot(): void
    {
        Notification::fake();
        $user = $this->applicantWithCv();
        $vacancy = JobVacancy::where('slug', 'certified-tower-rigger')->first();

        $this->get(route('applicant.vacancies.show', $vacancy))->assertOk()->assertSee('Submit application');

        $this->post(route('applicant.vacancies.apply', $vacancy), ['cover_note' => 'Five years on Dialog sites.', 'confirm_accurate' => '1'])
            ->assertRedirect(route('applicant.applications.index'));

        $application = JobApplication::firstOrFail();
        $this->assertSame(ApplicationStatus::Submitted, $application->status);
        $this->assertNotNull($application->cv_document_id);
        $this->assertSame(1, $application->statusHistory()->count());
        Notification::assertSentTo($user, TemplatedNotification::class, fn ($n) => $n->templateKey === 'application.submitted');

        // Second attempt refused.
        $this->post(route('applicant.vacancies.apply', $vacancy), ['confirm_accurate' => '1'])->assertSessionHasErrors('vacancy');
        $this->assertSame(1, JobApplication::count());

        // A later CV upload does not change the CV attached to the application.
        $cvId = $application->cv_document_id;
        $this->post(route('applicant.documents.store'), ['document_type' => 'cv', 'file' => $this->fakePdf('new.pdf')]);
        $this->assertSame($cvId, $application->fresh()->cv_document_id);

        $this->get(route('applicant.applications.index'))->assertOk()->assertSee('Certified Tower Rigger')->assertSee('Submitted');
    }

    public function test_cannot_apply_without_cv_or_to_closed_vacancy(): void
    {
        $user = $this->makeApplicant();
        $vacancy = JobVacancy::where('slug', 'certified-tower-rigger')->first();

        $this->actingAs($user)->post(route('applicant.vacancies.apply', $vacancy), ['confirm_accurate' => '1'])->assertSessionHasErrors('cv');

        $withCv = $this->applicantWithCv();
        $vacancy->update(['status' => 'closed']);
        $this->actingAs($withCv)->post(route('applicant.vacancies.apply', $vacancy), ['confirm_accurate' => '1'])->assertSessionHasErrors('vacancy');

        $this->assertSame(0, JobApplication::count());
    }

    public function test_dashboard_shows_widgets_and_in_app_notifications(): void
    {
        $user = $this->applicantWithCv();
        $vacancy = JobVacancy::where('slug', 'certified-tower-rigger')->first();
        $this->post(route('applicant.vacancies.apply', $vacancy), ['confirm_accurate' => '1']);

        $this->get(route('applicant.dashboard'))->assertOk()
            ->assertSee('Profile completion')
            ->assertSee('Application received: Certified Tower Rigger') // in-app notification
            ->assertSee('Certified Tower Rigger');

        $notification = $user->notifications()->first();
        $this->post(route('applicant.notifications.open', $notification->id))->assertRedirect(route('applicant.applications.index'));
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_cannot_open_someone_elses_notification(): void
    {
        $owner = $this->makeApplicant();
        $owner->notify(new TemplatedNotification('applicant.registered'));
        $id = $owner->notifications()->first()->id;

        $this->actingAs($this->makeApplicant())->post(route('applicant.notifications.open', $id))->assertNotFound();
    }
}
