<?php

namespace App\Filament\Widgets;

use App\Enums\AssignmentStatus;
use App\Models\WorkforceAssignment;
use Filament\Widgets\ChartWidget;

class AssignmentsTrendChart extends ChartWidget
{
    protected static ?int $sort = 6;

    protected ?string $heading = 'People deployed per month';

    protected ?string $maxHeight = '280px';

    public static function canView(): bool
    {
        return auth()->user()?->can('workforce.match') ?? false;
    }

    protected function getData(): array
    {
        $months = collect(range(5, 0))->map(fn ($i) => now()->startOfMonth()->subMonths($i));

        $data = $months->map(fn ($m) => WorkforceAssignment::query()
            ->whereIn('status', [AssignmentStatus::Active, AssignmentStatus::Completed, AssignmentStatus::Confirmed])
            ->whereDate('start_date', '<=', $m->copy()->endOfMonth())
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $m))
            ->distinct('applicant_profile_id')->count('applicant_profile_id'));

        return [
            'datasets' => [['label' => __('People on assignments'), 'data' => $data->all(), 'borderColor' => '#0f58a2', 'backgroundColor' => 'rgba(15,88,162,0.12)', 'fill' => true, 'tension' => 0.15]],
            'labels' => $months->map(fn ($m) => $m->format('M Y'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return ['plugins' => ['legend' => ['display' => false]], 'scales' => ['y' => ['ticks' => ['precision' => 0], 'beginAtZero' => true]]];
    }
}
