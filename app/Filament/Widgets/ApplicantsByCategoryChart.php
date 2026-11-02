<?php

namespace App\Filament\Widgets;

use App\Enums\VerificationStatus;
use App\Models\JobCategory;
use Filament\Widgets\ChartWidget;

class ApplicantsByCategoryChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Applicants by job category';

    protected ?string $maxHeight = '280px';

    public static function canView(): bool
    {
        return auth()->user()?->can('applicants.view') ?? false;
    }

    protected function getData(): array
    {
        $cats = JobCategory::orderBy('sort')
            ->withCount([
                'applicants as total',
                'applicants as verified' => fn ($q) => $q->where('verification_status', VerificationStatus::Verified),
            ])->get();

        return [
            'datasets' => [
                ['label' => __('All'), 'data' => $cats->pluck('total')->all(), 'backgroundColor' => '#aecdee'],
                ['label' => __('Verified'), 'data' => $cats->pluck('verified')->all(), 'backgroundColor' => '#0f58a2'],
            ],
            'labels' => $cats->pluck('name')->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return ['indexAxis' => 'y', 'scales' => ['x' => ['ticks' => ['precision' => 0]], 'y' => ['ticks' => ['autoSkip' => false]]]];
    }
}
