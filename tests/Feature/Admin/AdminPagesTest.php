<?php

namespace Tests\Feature\Admin;

use App\Enums\ServiceRequestStatus;
use App\Models\AuditLog;
use App\Models\JobVacancy;
use App\Models\NotificationTemplate;
use Database\Seeders\DemoVacancySeeder;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesApplicants;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class AdminPagesTest extends TestCase
{
    use CreatesApplicants, CreatesOrganizations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seed([ReferenceDataSeeder::class, RolesAndPermissionsSeeder::class, NotificationTemplateSeeder::class, DemoVacancySeeder::class]);
    }

    public function test_every_admin_page_renders_for_super_admin(): void
    {
        $admin = $this->makeStaff('Super Admin');
        $applicant = $this->makeApplicant(['nic_number' => '199012345678']);
        $contact = $this->makeOrgContact();
        $request = $this->makeServiceRequest($contact, ServiceRequestStatus::UnderReview);
        $this->makeQuotation($request);
        $vacancy = JobVacancy::first();
        $this->actingAs($applicant)->post(route('applicant.documents.store'), ['document_type' => 'cv', 'file' => $this->fakePdf()]);
        $this->actingAs($applicant)->post(route('applicant.vacancies.apply', $vacancy), ['confirm_accurate' => '1']);
        $application = $applicant->applicantProfile->jobApplications()->first();

        $this->actingAs($admin);
        foreach ([
            '/admin',
            '/admin/applicants', "/admin/applicants/{$applicant->applicantProfile->id}",
            '/admin/document-verification',
            '/admin/vacancies', '/admin/vacancies/create', "/admin/vacancies/{$vacancy->slug}/edit",
            '/admin/applications', "/admin/applications/{$application->id}",
            '/admin/interviews',
            '/admin/organizations', "/admin/organizations/{$contact->organizationMembership->organization_id}", "/admin/organizations/{$contact->organizationMembership->organization_id}/edit",
            '/admin/service-requests', "/admin/service-requests/{$request->reference}",
            '/admin/quotations',
            '/admin/email-templates', '/admin/email-templates/'.NotificationTemplate::first()->id.'/edit',
            '/admin/roles', '/admin/roles/create', '/admin/roles/'.Role::where('name', 'Recruiter')->value('id').'/edit',
            '/admin/system-settings',
            '/admin/staff', '/admin/portal-accounts', '/admin/audit-logs', '/admin/audit-logs/'.AuditLog::query()->value('id'),
        ] as $url) {
            $this->get($url)->assertOk();
        }

        // Super admin sees the full NIC on the applicant page.
        $this->get("/admin/applicants/{$applicant->applicantProfile->id}")->assertSee('199012345678');
        $this->assertTrue(AuditLog::where('action', 'applicant.viewed')->exists());

        // Opening a new application moves it to "Under review".
        $this->assertSame('under_review', $application->fresh()->status->value);
    }

    public function test_permission_matrix(): void
    {
        $contact = $this->makeOrgContact();
        $request = $this->makeServiceRequest($contact);
        $applicant = $this->makeApplicant(['nic_number' => '199012345678']);

        $matrix = [
            'Recruiter' => [
                'ok' => ['/admin/applicants', '/admin/vacancies', '/admin/applications', '/admin/interviews', '/admin/document-verification'],
                'forbidden' => ['/admin/service-requests', '/admin/quotations', '/admin/organizations', '/admin/system-settings', '/admin/roles', '/admin/email-templates'],
            ],
            'Operations Manager' => [
                'ok' => ['/admin/service-requests', "/admin/service-requests/{$request->reference}", '/admin/organizations', '/admin/quotations'],
                'forbidden' => ['/admin/vacancies', '/admin/applications', '/admin/system-settings', '/admin/document-verification'],
            ],
            'Finance' => [
                'ok' => ['/admin/quotations', '/admin/service-requests'],
                'forbidden' => ['/admin/applicants', '/admin/vacancies', '/admin/roles'],
            ],
            'Compliance Officer' => [
                'ok' => ['/admin/applicants', '/admin/document-verification'],
                'forbidden' => ['/admin/service-requests', '/admin/vacancies', '/admin/system-settings'],
            ],
        ];

        foreach ($matrix as $role => $expect) {
            $staff = $this->makeStaff($role);
            foreach ($expect['ok'] as $url) {
                $this->actingAs($staff)->get($url)->assertOk();
            }
            foreach ($expect['forbidden'] as $url) {
                $this->actingAs($staff)->get($url)->assertForbidden();
            }
        }

        // Recruiter sees a masked NIC.
        $this->actingAs($this->makeStaff('Recruiter'))->get("/admin/applicants/{$applicant->applicantProfile->id}")
            ->assertDontSee('199012345678')->assertSee('5678');
    }
}
