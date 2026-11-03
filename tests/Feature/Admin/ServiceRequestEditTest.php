<?php

namespace Tests\Feature\Admin;

use App\Enums\ServiceRequestStatus;
use App\Filament\Resources\ServiceRequests\Pages\ViewServiceRequest;
use App\Models\AuditLog;
use App\Models\District;
use App\Models\ServiceCategory;
use App\Models\ServiceRequestMessage;
use App\Models\Skill;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\CreatesApplicants;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class ServiceRequestEditTest extends TestCase
{
    use CreatesApplicants, CreatesOrganizations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed([ReferenceDataSeeder::class, RolesAndPermissionsSeeder::class, NotificationTemplateSeeder::class]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function form(array $overrides = []): array
    {
        return array_merge([
            'service_category_id' => ServiceCategory::orderByDesc('id')->first()->id,
            'required_headcount' => 6,
            'title' => 'Antenna swap — 15 sites',
            'description' => 'Swap antennas at 15 sites in the Western and Southern Provinces.',
            'skills' => Skill::limit(2)->pluck('id')->all(),
            'required_experience_years' => 3,
            'requires_police_clearance' => true,
            'required_qualifications' => 'NVQ Level 4',
            'district_id' => District::orderByDesc('id')->first()->id,
            'location_text' => 'Galle and Matara sites',
            'start_date' => now()->addMonth()->toDateString(),
            'expected_duration_value' => 3,
            'expected_duration_unit' => 'weeks',
            'working_hours' => '8am–5pm',
            'budget_amount' => 750000,
            'special_requirements' => 'Working at height certificate',
            'reason' => 'Client confirmed extra sites by phone',
            'tell_client' => false,
        ], $overrides);
    }

    public function test_operations_manager_edits_request_and_change_is_audited(): void
    {
        $request = $this->makeServiceRequest($this->makeOrgContact(), ServiceRequestStatus::UnderReview);
        $keep = Skill::first();
        $request->skills()->attach($keep->id, ['min_years' => 2]);
        $this->actingAs($this->makeStaff('Operations Manager'));

        Livewire::test(ViewServiceRequest::class, ['record' => $request->getRouteKey()])
            ->callAction('editDetails', data: $this->form(['skills' => [$keep->id, Skill::skip(1)->first()->id]]))
            ->assertHasNoActionErrors();

        $request->refresh();
        $this->assertSame('Antenna swap — 15 sites', $request->title);
        $this->assertSame(6, (int) $request->required_headcount);
        $this->assertSame('Galle and Matara sites', $request->location_text);
        $this->assertSame('weeks', $request->expected_duration_unit);
        $this->assertEquals(750000, (float) $request->budget_amount);
        $this->assertSame(ServiceRequestStatus::UnderReview, $request->status); // status untouched
        $this->assertEquals(2, (float) $request->skills()->find($keep->id)->pivot->min_years); // kept skill keeps its years
        $this->assertCount(2, $request->skills);

        $log = AuditLog::where('action', 'service_request.details_updated')->firstOrFail();
        $this->assertSame('Client confirmed extra sites by phone', $log->meta['reason']);
        $this->assertContains('title', $log->meta['fields']);
        $this->assertContains('skills', $log->meta['fields']);
        $this->assertSame(0, ServiceRequestMessage::count()); // client not told
    }

    public function test_client_can_be_told_through_the_conversation(): void
    {
        $contact = $this->makeOrgContact();
        $request = $this->makeServiceRequest($contact);
        $this->actingAs($this->makeStaff('Operations Manager'));

        Livewire::test(ViewServiceRequest::class, ['record' => $request->getRouteKey()])
            ->callAction('editDetails', data: $this->form(['tell_client' => true]))
            ->assertHasNoActionErrors();

        $message = ServiceRequestMessage::firstOrFail();
        $this->assertFalse((bool) $message->is_internal);
        $this->assertStringContainsString('Client confirmed extra sites by phone', $message->body);
        Notification::assertSentTo($contact, \App\Notifications\TemplatedNotification::class);
    }

    public function test_validation_and_no_op(): void
    {
        $request = $this->makeServiceRequest($this->makeOrgContact());
        $this->actingAs($this->makeStaff('Operations Manager'));

        Livewire::test(ViewServiceRequest::class, ['record' => $request->getRouteKey()])
            ->callAction('editDetails', data: $this->form(['title' => 'x', 'required_headcount' => 0, 'expected_duration_unit' => null, 'reason' => '']))
            ->assertHasActionErrors(['title', 'required_headcount', 'expected_duration_unit', 'reason']);

        // Submitting the current values changes nothing and logs nothing.
        Livewire::test(ViewServiceRequest::class, ['record' => $request->getRouteKey()])
            ->callAction('editDetails', data: ['reason' => 'checking'])
            ->assertHasNoActionErrors();
        $this->assertFalse(AuditLog::where('action', 'service_request.details_updated')->exists());
    }

    public function test_hidden_for_closed_requests_and_view_only_roles(): void
    {
        $open = $this->makeServiceRequest($this->makeOrgContact());
        $closed = $this->makeServiceRequest($this->makeOrgContact(), ServiceRequestStatus::Completed);

        $this->actingAs($this->makeStaff('Finance')); // view only
        Livewire::test(ViewServiceRequest::class, ['record' => $open->getRouteKey()])->assertActionHidden('editDetails');

        $this->actingAs($this->makeStaff('Operations Manager'));
        Livewire::test(ViewServiceRequest::class, ['record' => $closed->getRouteKey()])->assertActionHidden('editDetails');
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Actions\Admin\StaffRequestActions::class)->updateDetails($closed, $this->form(), $this->makeStaff('Super Admin'), 'x');
    }
}
