<?php

namespace Tests\Feature\Reports;

use App\Enums\ServiceRequestStatus;
use App\Filament\Pages\Reports;
use App\Jobs\GenerateReportExport;
use App\Models\ApplicantDocument;
use App\Models\AuditLog;
use App\Models\District;
use App\Models\JobApplication;
use App\Models\JobCategory;
use App\Models\JobVacancy;
use App\Models\PoliceClearanceRecord;
use App\Models\StoredFile;
use App\Models\WorkforceAssignment;
use App\Notifications\TemplatedNotification;
use App\Support\Documents\DocumentVault;
use App\Support\Reports\ReportExporter;
use App\Support\Reports\ReportRegistry;
use Database\Seeders\DemoVacancySeeder;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\CreatesApplicants;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use CreatesApplicants, CreatesOrganizations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 10:00');
        Storage::fake('documents');
        $this->seed([ReferenceDataSeeder::class, RolesAndPermissionsSeeder::class, NotificationTemplateSeeder::class, DemoVacancySeeder::class]);
        $this->seedData();
    }

    private array $people = [];

    private function seedData(): void
    {
        $tower = JobCategory::where('slug', 'tower-construction-and-maintenance')->value('id');
        $drivers = JobCategory::where('slug', 'drivers-and-transportation')->value('id');
        $gampaha = District::where('name_en', 'Gampaha')->value('id');
        $kandy = District::where('name_en', 'Kandy')->value('id');

        foreach ([
            ['Amal Perera', 'verified', $gampaha, [$tower], '2026-11-20', '199012345678'],
            ['Bimal Silva', 'verified', $gampaha, [$tower, $drivers], '2027-08-01', '198812345678'],
            ['Chamari Fernando', 'unverified', $kandy, [$drivers], null, '199512345678'],
        ] as [$name, $verified, $district, $cats, $pcc, $nic]) {
            $p = $this->makeApplicant(['full_name' => $name, 'verification_status' => $verified, 'district_id' => $district, 'nic_number' => $nic])->applicantProfile;
            $p->jobCategories()->attach($cats);
            if ($pcc) {
                $file = StoredFile::create(['uuid' => (string) Str::uuid(), 'disk' => 'documents', 'path' => 'x', 'original_name' => 'p.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1, 'sha256' => str_repeat('0', 64)]);
                $doc = ApplicantDocument::create(['applicant_profile_id' => $p->id, 'document_type' => 'police_clearance', 'stored_file_id' => $file->id]);
                $rec = PoliceClearanceRecord::forceCreate(['applicant_profile_id' => $p->id, 'applicant_document_id' => $doc->id, 'issue_date' => '2026-01-01', 'uploaded_at' => now(), 'verification_status' => 'approved', 'valid_until' => $pcc]);
                $p->forceFill(['current_police_clearance_id' => $rec->id])->save();
            }
            $this->people[$name] = $p;
        }

        $vacancy = JobVacancy::first();
        foreach (['Amal Perera' => 'selected', 'Bimal Silva' => 'rejected'] as $name => $status) {
            JobApplication::forceCreate(['job_vacancy_id' => $vacancy->id, 'applicant_profile_id' => $this->people[$name]->id, 'status' => $status, 'submitted_at' => now()]);
        }

        $contact = $this->makeOrgContact();
        $done = $this->makeServiceRequest($contact, ServiceRequestStatus::Completed, ['completed_at' => now()->subDays(3), 'district_id' => $gampaha]);
        $this->makeQuotation($done, \App\Enums\QuotationStatus::Accepted, ['total' => 750000]);
        $this->makeServiceRequest($contact, ServiceRequestStatus::Submitted);
        WorkforceAssignment::forceCreate(['service_request_id' => $done->id, 'applicant_profile_id' => $this->people['Bimal Silva']->id, 'role_title' => 'Rigger', 'start_date' => '2026-09-01', 'end_date' => '2026-10-15', 'status' => 'completed']);
    }

    private function rows(string $key, array $filters = []): array
    {
        return iterator_to_array(ReportRegistry::get($key)->rows($filters), false);
    }

    public function test_all_twelve_reports_run_and_never_include_nic(): void
    {
        $this->assertCount(12, ReportRegistry::all());

        foreach (ReportRegistry::all() as $key => $report) {
            $rows = $this->rows($key);
            $this->assertSame(count($rows), $report->count([]), "count matches rows: {$key}");
            foreach ($rows as $row) {
                $this->assertSame(array_keys($report->columns), array_keys($row), "columns: {$key}");
            }
            $dump = json_encode($rows).json_encode($report->summary([]));
            foreach (['199012345678', '198812345678', '199512345678'] as $nic) {
                $this->assertStringNotContainsString($nic, $dump, "NIC leaked in {$key}");
            }
        }
    }

    public function test_report_figures_and_filters(): void
    {
        $byCat = collect($this->rows('applicants_by_category'))->keyBy('category');
        $this->assertSame(2, $byCat['Tower Construction and Maintenance']['total']);
        $this->assertSame(2, $byCat['Tower Construction and Maintenance']['verified']);
        $this->assertSame(1, $byCat['Tower Construction and Maintenance']['pcc_valid']); // Amal is expiring soon, Bimal valid
        $this->assertSame(2, $byCat['Drivers and Transportation']['total']);

        $byDistrict = collect($this->rows('applicants_by_district'))->keyBy('district');
        $this->assertSame(2, $byDistrict['Gampaha']['total']);
        $this->assertSame(1, $byDistrict['Kandy']['total']);
        $this->assertSame(0, $byDistrict['Kandy']['verified']);

        $this->assertSame(['Chamari Fernando'], array_column($this->rows('verification', ['status' => 'unverified']), 'name'));

        $renewals = $this->rows('upcoming_renewals', ['days' => 30]);
        $this->assertSame(['Amal Perera'], array_column($renewals, 'name'));
        $this->assertSame(18, $renewals[0]['days_left']);
        $this->assertCount(2, $this->rows('upcoming_renewals', ['days' => 365]));

        $this->assertSame(['Amal Perera'], array_column($this->rows('selected_applicants'), 'applicant'));
        $this->assertCount(2, $this->rows('job_applications'));
        $this->assertCount(0, $this->rows('job_applications', ['from' => '2026-11-03']));

        $completed = $this->rows('completed_projects');
        $this->assertCount(1, $completed);
        $this->assertSame(750000.0, $completed[0]['value']);
        $this->assertSame(1, $completed[0]['team']);

        $this->assertSame('750,000.00', ReportRegistry::get('service_requests')->summary([])['Accepted value (LKR)']);
        $this->assertSame(['Bimal Silva'], array_column($this->rows('assignments'), 'person'));
        $this->assertSame('Expiring Soon', collect($this->rows('police_clearance'))->firstWhere('name', 'Amal Perera')['status']);
    }

    public function test_excel_and_pdf_exports(): void
    {
        $this->actingAs($this->makeStaff('HR Manager'));
        $exporter = app(ReportExporter::class);
        $report = ReportRegistry::get('applicants');

        $xlsx = $exporter->render($report, [], 'xlsx');
        $this->assertStringStartsWith('PK', $xlsx);
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        file_put_contents($path, $xlsx);
        $sheet = IOFactory::load($path)->getActiveSheet();
        $this->assertSame('Name', $sheet->getCell('A1')->getValue());
        $this->assertSame('Registered', $sheet->getCell('K1')->getValue());
        $this->assertSame(4, $sheet->getHighestRow()); // heading + 3 applicants

        $this->assertStringStartsWith('%PDF', $exporter->render($report, [], 'pdf'));

        config(['portal.reports.pdf_limit' => 2]);
        $this->expectException(\RuntimeException::class);
        $exporter->render($report, [], 'pdf');
    }

    public function test_reports_page_preview_export_and_audit(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $hr = $this->makeStaff('HR Manager');
        $this->actingAs($hr)->get('/admin/reports?report=upcoming_renewals')->assertOk()->assertSee('Amal Perera');

        Livewire::test(Reports::class)
            ->set('reportKey', 'applicants')
            ->set('filters.district_id', (string) District::where('name_en', 'Kandy')->value('id'))
            ->assertSee('Chamari Fernando')->assertDontSee('Amal Perera')
            ->call('export', 'xlsx')
            ->assertFileDownloaded();

        $log = AuditLog::where('action', 'report.exported')->firstOrFail();
        $this->assertSame('applicants', $log->meta['report']);
        $this->assertFalse($log->meta['queued']);
        $this->assertArrayHasKey('district_id', $log->meta['filters']);

        // Unknown filter keys / bad values are ignored, not passed to queries.
        $page = Livewire::test(Reports::class)->set('reportKey', 'applicants')
            ->set('filters', ['district_id' => '1 OR 1=1', 'status' => 'hacker', 'evil' => 'x']);
        $this->assertSame([], $page->instance()->cleanFilters());
    }

    public function test_large_exports_are_queued_and_downloadable_only_by_requester(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['portal.reports.inline_limit' => 1]);
        $hr = $this->makeStaff('HR Manager');
        $this->actingAs($hr);

        Queue::fake();
        Livewire::test(Reports::class)->set('reportKey', 'applicants')->call('export', 'xlsx')->assertNoFileDownloaded();
        Queue::assertPushed(GenerateReportExport::class, fn ($job) => $job->userId === $hr->id && $job->format === 'xlsx');

        // Run the job for real.
        Notification::fake();
        (new GenerateReportExport($hr->id, 'applicants', [], 'xlsx'))->handle(app(ReportExporter::class));

        $file = StoredFile::where('uploaded_by', $hr->id)->where('original_name', 'like', 'waveteam-applicants-%.xlsx')->firstOrFail();
        $this->assertStringStartsWith('PK', app(DocumentVault::class)->contents($file));
        Notification::assertSentTo($hr, TemplatedNotification::class, fn ($n) => $n->templateKey === 'report.ready' && str_contains($n->actionUrl, $file->uuid));

        $this->actingAs($hr)->get(route('reports.download', $file))->assertOk()->assertDownload($file->original_name);
        $this->actingAs($this->makeStaff('HR Manager'))->get(route('reports.download', $file))->assertNotFound();
        $this->actingAs($this->people['Amal Perera']->user)->get(route('reports.download', $file))->assertNotFound();
    }

    public function test_report_permissions(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->makeStaff('Recruiter'))->get('/admin/reports')->assertForbidden();

        $finance = $this->makeStaff('Finance'); // reports.view but not reports.export
        $this->actingAs($finance)->get('/admin/reports')->assertOk()->assertSee('You can view reports but not export them.');
        Livewire::actingAs($finance)->test(Reports::class)->call('export', 'xlsx')->assertForbidden();

        // Dashboard charts (lazy-loaded widgets) render with real data.
        $admin = $this->makeStaff('Super Admin');
        $this->actingAs($admin)->get('/admin')->assertOk();
        foreach ([\App\Filament\Widgets\ApplicantsByCategoryChart::class, \App\Filament\Widgets\PoliceClearanceChart::class,
                  \App\Filament\Widgets\ServiceRequestsChart::class, \App\Filament\Widgets\AssignmentsTrendChart::class] as $widget) {
            Livewire::actingAs($admin)->test($widget)->assertOk();
        }
        $data = (fn () => $this->getData())->call(new \App\Filament\Widgets\PoliceClearanceChart);
        $this->assertSame([1, 1, 0, 0, 0, 1], $data['datasets'][0]['data']); // valid, expiring, expired, pending, rejected, missing
    }
}
