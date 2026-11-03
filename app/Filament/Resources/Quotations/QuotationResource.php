<?php

namespace App\Filament\Resources\Quotations;

use App\Enums\QuotationStatus;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Resources\Quotations\Pages\ListQuotations;
use App\Filament\Resources\ServiceRequests\ServiceRequestResource;
use App\Actions\Admin\QuotationActions;
use App\Models\Quotation;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Validation\ValidationException;
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
        $query = parent::getEloquentQuery()->with(['serviceRequest.organization', 'preparer', 'approver', 'pdf']);

        // Only staff who may delete quotations can see (and restore) deleted ones.
        return auth()->user()?->can('quotations.delete')
            ? $query->withoutGlobalScopes([SoftDeletingScope::class])
            : $query;
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
                TextColumn::make('status')->badge()
                    ->description(fn (Quotation $r) => $r->trashed() ? __('Deleted') : null),
                TextColumn::make('preparer.name')->label(__('Prepared by'))->toggleable(),
                TextColumn::make('approver.name')->label(__('Approved by'))->placeholder('—')->toggleable(),
                TextColumn::make('responded_at')->label(__('Client response'))->since()->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(QuotationStatus::options()),
                TrashedFilter::make()->label(__('Deleted quotations'))
                    ->placeholder(__('Hide deleted'))->trueLabel(__('Show deleted too'))->falseLabel(__('Only deleted'))
                    ->visible(fn () => auth()->user()?->can('quotations.delete')),
            ])
            ->recordActions([
                QuotationForm::editAction(),
                QuotationForm::sendAction(),
                Action::make('pdf')->label('PDF')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                    ->visible(fn (Quotation $r) => $r->pdf_file_id !== null)
                    ->url(fn (Quotation $r) => route('files.show', [$r->pdf, 'download' => 1])),
                static::deleteAction(),
                static::restoreAction(),
            ]);
    }

    /** Row action: delete a quotation (kept and restorable). */
    public static function deleteAction(string $name = 'delete'): Action
    {
        return Action::make($name)->label(__('Delete'))->icon(Heroicon::OutlinedTrash)->color('danger')
            ->visible(fn (Quotation $record) => ! $record->trashed() && $record->status !== QuotationStatus::Accepted && auth()->user()->can('quotations.delete'))
            ->modalHeading(fn (Quotation $record) => __('Delete quotation :ref?', ['ref' => $record->reference]))
            ->modalDescription(__('The quotation disappears from the staff lists and from the client’s portal. Its PDF and line items are kept, and it can be restored from “Deleted quotations”. Accepted quotations cannot be deleted.'))
            ->modalSubmitActionLabel(__('Delete'))
            ->schema([Textarea::make('reason')->label(__('Reason (kept in the audit log)'))->required()->rows(3)->maxLength(1000)])
            ->action(function (Quotation $record, array $data, Action $action) {
                try {
                    app(QuotationActions::class)->delete($record, auth()->user(), $data['reason']);
                } catch (ValidationException $e) {
                    Notification::make()->title(__('This quotation cannot be deleted'))->body(collect($e->errors())->flatten()->first())->danger()->send();
                    $action->halt();
                }
                Notification::make()->title(__(':ref deleted', ['ref' => $record->reference]))->success()->send();
            });
    }

    public static function restoreAction(string $name = 'restore'): Action
    {
        return Action::make($name)->label(__('Restore'))->icon(Heroicon::OutlinedArrowUturnLeft)->color('success')
            ->visible(fn (Quotation $record) => $record->trashed() && auth()->user()->can('quotations.delete'))
            ->requiresConfirmation()
            ->modalDescription(__('The quotation returns to the lists. If it was sent and a newer quotation has been sent since, it comes back as superseded.'))
            ->action(function (Quotation $record) {
                app(QuotationActions::class)->restore($record, auth()->user());
                Notification::make()->title(__(':ref restored', ['ref' => $record->reference]))->success()->send();
            });
    }

    public static function getPages(): array
    {
        return ['index' => ListQuotations::route('/')];
    }
}
