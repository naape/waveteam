<?php

namespace App\Filament\Pages;

use App\Jobs\GenerateReportExport;
use App\Models\District;
use App\Models\JobCategory;
use App\Support\Reports\Report;
use App\Support\Reports\ReportExporter;
use App\Support\Reports\ReportRegistry;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Report centre: pick a report, filter, preview, export to Excel or PDF.
 */
class Reports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Reports';

    protected string $view = 'filament.reports';

    #[Url(as: 'report')]
    public string $reportKey = 'applicants';

    #[Url]
    public array $filters = [];

    public const PREVIEW_ROWS = 25;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('reports.view') ?? false;
    }

    public function mount(): void
    {
        if (! array_key_exists($this->reportKey, ReportRegistry::all())) {
            $this->reportKey = 'applicants';
        }
    }

    public function updatedReportKey(): void
    {
        $this->filters = [];
    }

    public function getReport(): Report
    {
        return ReportRegistry::get($this->reportKey);
    }

    /** Only the filters this report accepts, validated and normalised. */
    public function cleanFilters(): array
    {
        $report = $this->getReport();
        $clean = [];

        foreach ($report->filters as $key) {
            $value = $this->filters[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $clean[$key] = match ($key) {
                'from', 'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) ? $value : null,
                'job_category_id', 'district_id' => ctype_digit((string) $value) ? (int) $value : null,
                'days' => max(1, min(365, (int) $value)),
                'status' => array_key_exists($value, ReportRegistry::statusOptions($report->key)) ? $value : null,
                default => null,
            };
        }

        return array_filter($clean, fn ($v) => $v !== null);
    }

    public function getViewData(): array
    {
        $report = $this->getReport();
        $filters = $this->cleanFilters();
        $preview = [];
        foreach ($report->rows($filters) as $i => $row) {
            if (count($preview) >= self::PREVIEW_ROWS) {
                break;
            }
            $preview[] = $row;
        }

        return [
            'reports' => ReportRegistry::all(),
            'report' => $report,
            'summary' => $report->summary($filters),
            'count' => $report->count($filters),
            'preview' => $preview,
            'categories' => JobCategory::orderBy('sort')->pluck('name', 'id'),
            'districts' => District::orderBy('name_en')->pluck('name_en', 'id'),
            'statuses' => ReportRegistry::statusOptions($report->key),
            'canExport' => auth()->user()->can('reports.export'),
        ];
    }

    public function export(string $format): ?StreamedResponse
    {
        abort_unless(auth()->user()->can('reports.export'), 403);
        abort_unless(in_array($format, ['xlsx', 'pdf'], true), 400);

        $report = $this->getReport();
        $filters = $this->cleanFilters();
        $count = $report->count($filters);

        if ($format === 'pdf' && $count > ReportExporter::pdfLimit()) {
            Notification::make()->title(__('Too many rows for PDF (:n). Please export to Excel.', ['n' => number_format($count)]))->warning()->send();

            return null;
        }

        if ($count > ReportExporter::inlineLimit()) {
            GenerateReportExport::dispatch(auth()->id(), $report->key, $filters, $format);
            ReportExporter::audit($report, $filters, $format, $count, queued: true);
            Notification::make()->title(__('Large report (:n rows) — preparing in the background.', ['n' => number_format($count)]))
                ->body(__('We will email you a private download link when it is ready.'))->success()->send();

            return null;
        }

        try {
            $exporter = app(ReportExporter::class);
            $bytes = $exporter->render($report, $filters, $format);
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return null;
        }

        ReportExporter::audit($report, $filters, $format, $count, queued: false);

        return response()->streamDownload(fn () => print ($bytes), $exporter->filename($report, $format), [
            'Content-Type' => ReportExporter::MIME[$format],
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
