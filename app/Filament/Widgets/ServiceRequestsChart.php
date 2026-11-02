<?php

namespace App\Filament\Widgets;

use App\Enums\ServiceRequestStatus;
use App\Models\ServiceRequest;
use Filament\Widgets\ChartWidget;

class ServiceRequestsChart extends ChartWidget
{
    protected static ?int $sort = 5;

    protected ?string $heading = 'Open service requests by stage';

    protected ?string $maxHeight = '280px';

    public static function canView(): bool
    {
        return auth()->user()?->can('service_requests.view') ?? false;
    }

    protected function getData(): array
    {
        $statuses = array_values(array_filter(ServiceRequestStatus::cases(), fn ($s) => ! $s->isClosed()));
        $counts = ServiceRequest::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return [
            'datasets' => [['label' => __('Requests'), 'data' => array_map(fn ($s) => (int) ($counts[$s->value] ?? 0), $statuses), 'backgroundColor' => '#5cc5c8']],
            'labels' => array_map(fn ($s) => $s->getLabel(), $statuses),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return ['plugins' => ['legend' => ['display' => false]], 'scales' => ['y' => ['ticks' => ['precision' => 0]]]];
    }
}
