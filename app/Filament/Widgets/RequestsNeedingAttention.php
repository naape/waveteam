<?php

namespace App\Filament\Widgets;

use App\Enums\ServiceRequestStatus;
use App\Filament\Resources\ServiceRequests\ServiceRequestResource;
use App\Models\ServiceRequest;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RequestsNeedingAttention extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Service requests needing attention';

    public static function canView(): bool
    {
        return auth()->user()?->can('service_requests.view') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => ServiceRequest::query()
                ->whereIn('status', [ServiceRequestStatus::Submitted, ServiceRequestStatus::UnderReview, ServiceRequestStatus::QuotationPreparation, ServiceRequestStatus::Approved])
                ->with(['organization', 'manager']))
            ->defaultSort('created_at')
            ->paginated([5, 10])
            ->defaultPaginationPageOption(5)
            ->recordUrl(fn (ServiceRequest $r) => ServiceRequestResource::getUrl('view', ['record' => $r]))
            ->columns([
                TextColumn::make('reference')->fontFamily('mono'),
                TextColumn::make('title')->limit(45),
                TextColumn::make('organization.name')->label(__('Organisation'))->limit(30),
                TextColumn::make('manager.name')->label(__('Manager'))->placeholder(__('Unassigned')),
                TextColumn::make('status')->badge(),
                TextColumn::make('created_at')->label(__('Waiting'))->since(),
            ])
            ->emptyStateHeading(__('Nothing waiting — all caught up.'));
    }
}
