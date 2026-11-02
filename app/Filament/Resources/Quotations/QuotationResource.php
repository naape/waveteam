<?php

namespace App\Filament\Resources\Quotations;

use App\Enums\QuotationStatus;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Resources\Quotations\Pages\ListQuotations;
use App\Filament\Resources\ServiceRequests\ServiceRequestResource;
use App\Models\Quotation;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class QuotationResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = Quotation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'Clients & Services';

    protected static ?int $navigationSort = 3;

    protected static array $permissions = ['view' => 'quotations.view'];

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['serviceRequest.organization', 'preparer', 'approver', 'pdf']);
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }
        $n = Quotation::where('status', QuotationStatus::Draft)->count();

        return $n ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->fontFamily('mono')->searchable()->sortable()->suffix(fn (Quotation $r) => ' · v'.$r->version),
                TextColumn::make('serviceRequest.reference')->label(__('Request'))->fontFamily('mono')->color('primary')->searchable()
                    ->url(fn (Quotation $r) => ServiceRequestResource::getUrl('view', ['record' => $r->serviceRequest])),
                TextColumn::make('serviceRequest.organization.name')->label(__('Organisation'))->limit(30),
                TextColumn::make('total')->money('LKR')->sortable(),
                TextColumn::make('valid_until')->date()->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('preparer.name')->label(__('Prepared by'))->toggleable(),
                TextColumn::make('approver.name')->label(__('Approved by'))->placeholder('—')->toggleable(),
                TextColumn::make('responded_at')->label(__('Client response'))->since()->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([SelectFilter::make('status')->options(QuotationStatus::options())])
            ->recordActions([
                QuotationForm::editAction(),
                QuotationForm::sendAction(),
                Action::make('pdf')->label('PDF')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                    ->visible(fn (Quotation $r) => $r->pdf_file_id !== null)
                    ->url(fn (Quotation $r) => route('files.show', [$r->pdf, 'download' => 1])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListQuotations::route('/')];
    }
}
