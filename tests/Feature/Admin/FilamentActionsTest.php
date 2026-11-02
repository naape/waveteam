<?php

namespace Tests\Feature\Admin;

use App\Enums\QuotationStatus;
use App\Enums\ServiceRequestStatus;
use App\Filament\Pages\SystemSettings;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\ServiceRequests\Pages\ViewServiceRequest;
use App\Filament\Resources\Vacancies\Pages\CreateVacancy;
use App\Models\JobCategory;
use App\Models\JobVacancy;
use App\Support\Settings;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesApplicants;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

/**
 * Exercises real Filament actions/forms through Livewire (UI wiring).
 */
class FilamentActionsTest extends TestCase
{
    use CreatesApplicants, CreatesOrganizations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Notification::fake();
        $this->seed([ReferenceDataSeeder::class, RolesAndPermissionsSeeder::class, NotificationTemplateSeeder::class]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_prepare_and_send_quotation_from_request_page(): void
    {
        $admin = $this->makeStaff('Super Admin');
        $request = $this->makeServiceRequest($this->makeOrgContact(), ServiceRequestStatus::UnderReview);
        $this->actingAs($admin);

        Livewire::test(ViewServiceRequest::class, ['record' => $request->getRouteKey()])
            ->callAction('newQuotation', data: [
                'items' => [['description' => 'Earthing works', 'quantity' => 8, 'unit' => 'site', 'unit_price' => 50000]],
                'tax_rate' => 0,
                'valid_until' => now()->addDays(20)->toDateString(),
                'terms' => 'Net 30',
            ])
            ->assertHasNoActionErrors();

        $quote = $request->quotations()->firstOrFail();
        $this->assertEquals(400000, (float) $quote->total);

        Livewire::test(ViewServiceRequest::class, ['record' => $request->getRouteKey()])
            ->callAction('sendQuotation')
            ->assertHasNoActionErrors();

        $this->assertSame(QuotationStatus::Sent, $quote->fresh()->status);
        $this->assertSame(ServiceRequestStatus::QuotationSent, $request->fresh()->status);
    }

    public function test_reply_and_status_change_from_request_page(): void
    {
        $admin = $this->makeStaff('Super Admin');
        $request = $this->makeServiceRequest($this->makeOrgContact());
        $this->actingAs($admin);

        Livewire::test(ViewServiceRequest::class, ['record' => $request->getRouteKey()])
            ->callAction('reply', data: ['body' => 'Thanks — reviewing now.'])
            ->assertHasNoActionErrors()
            ->callAction('status', data: ['status' => 'under_review'])
            ->assertHasNoActionErrors();

        $this->assertSame(ServiceRequestStatus::UnderReview, $request->fresh()->status);
        $this->assertSame('Thanks — reviewing now.', $request->messages()->first()->body);
    }

    public function test_table_filters_really_filter(): void
    {
        // Regression: closures must name their parameter $query; a "$q" is
        // resolved by the container as a *new empty* builder and the filter
        // silently does nothing.
        $this->actingAs($this->makeStaff('Super Admin'));
        $contact = $this->makeOrgContact();
        $open = $this->makeServiceRequest($contact);
        $done = $this->makeServiceRequest($contact, ServiceRequestStatus::Completed);

        Livewire::test(\App\Filament\Resources\ServiceRequests\Pages\ListServiceRequests::class)
            ->assertCanSeeTableRecords([$open])
            ->assertCanNotSeeTableRecords([$done]); // "Open only" is on by default

        $experienced = $this->makeApplicant(['total_experience_years' => 8]);
        $junior = $this->makeApplicant(['total_experience_years' => 1]);
        Livewire::test(\App\Filament\Resources\Applicants\Pages\ListApplicants::class)
            ->filterTable('experience', ['min_years' => 5])
            ->assertCanSeeTableRecords([$experienced->applicantProfile])
            ->assertCanNotSeeTableRecords([$junior->applicantProfile])
            ->resetTableFilters()
            ->searchTable('199012345678')
            ->assertCountTableRecords(0);
    }

    public function test_create_vacancy_generates_unique_slug(): void
    {
        $this->actingAs($this->makeStaff('HR Manager'));
        $data = [
            'title' => 'Tower Rigger', 'job_category_id' => JobCategory::first()->id, 'employment_type' => 'contract',
            'description' => 'Climb towers.', 'positions' => 2, 'min_experience_years' => 1,
            'requires_police_clearance' => true, 'status' => 'published',
        ];

        Livewire::test(CreateVacancy::class)->fillForm($data)->call('create')->assertHasNoFormErrors();
        Livewire::test(CreateVacancy::class)->fillForm($data)->call('create')->assertHasNoFormErrors();

        $this->assertSame(['tower-rigger', 'tower-rigger-2'], JobVacancy::orderBy('id')->pluck('slug')->all());
        $this->assertNotNull(JobVacancy::first()->published_at);
    }

    public function test_settings_page_validates_and_saves(): void
    {
        $this->actingAs($this->makeStaff('Super Admin'));

        Livewire::test(SystemSettings::class)
            ->fillForm(['w_skills' => 50]) // now sums to 110
            ->call('save')
            ->assertHasErrors();
        $this->assertSame(40, Settings::get('matching.weights')['skills']);

        Livewire::test(SystemSettings::class)
            ->fillForm(['pcc_validity' => 6, 'pcc_reminder_days' => ['45', '15', '15'], 'upload_max_mb' => 8])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(6, Settings::get('pcc.default_validity_months'));
        $this->assertSame([45, 15], Settings::get('pcc.reminder_days'));
        $this->assertSame(8192, Settings::get('uploads.max_kb'));
    }

    public function test_settings_page_requires_permission(): void
    {
        $this->actingAs($this->makeStaff('HR Manager'))->get('/admin/system-settings')->assertForbidden();
    }

    public function test_role_permissions_can_be_edited_but_not_super_admin(): void
    {
        $this->actingAs($this->makeStaff('Super Admin'));
        $recruiter = Role::where('name', 'Recruiter')->first();
        $pccView = \Spatie\Permission\Models\Permission::where('name', 'police_clearance.view')->first();

        $component = Livewire::test(EditRole::class, ['record' => $recruiter->getKey()]);
        $state = $component->get('data');
        $state['permissions_police_clearance'] = [...($state['permissions_police_clearance'] ?? []), $pccView->id];
        $component->set('data', $state)->call('save')->assertHasNoFormErrors();

        $this->assertTrue($recruiter->fresh()->hasPermissionTo('police_clearance.view'));
        $this->assertTrue($recruiter->fresh()->hasPermissionTo('vacancies.manage'), 'existing permissions kept');

        $this->get('/admin/roles/'.Role::where('name', 'Super Admin')->value('id').'/edit')->assertForbidden();
    }
}
