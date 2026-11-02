<?php

namespace App\Filament\Widgets;

use App\Enums\PoliceClearanceStatus;
use App\Models\ApplicantProfile;
use Filament\Widgets\ChartWidget;

class PoliceClearanceChart extends ChartWidget
{
    protected static ?int $sort = 4;

    protected ?string $heading = 'Police clearance status';

    protected ?string $maxHeight = '280px';

    public static function canView(): bool
    {
        $u = auth()->user();

        return $u && ($u->can('police_clearance.view') || $u->can('police_clearance.verify') || $u->can('reports.view'));
    }

    protected function getData(): array
    {
        $colors = [
            'valid' => '#7bc443', 'expiring_soon' => '#f59e0b', 'expired' => '#e11d48',
            'pending_verification' => '#5cc5c8', 'rejected' => '#9f1239', 'missing' => '#cbd5e1',
        ];
        $statuses = PoliceClearanceStatus::cases();

        return [
            'datasets' => [[
                'data' => array_map(fn ($s) => ApplicantProfile::query()->wherePccStatus($s)->count(), $statuses),
                'backgroundColor' => array_map(fn ($s) => $colors[$s->value], $statuses),
            ]],
            'labels' => array_map(fn ($s) => $s->getLabel(), $statuses),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
