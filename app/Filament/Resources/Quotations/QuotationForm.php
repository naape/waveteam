<?php

namespace App\Filament\Resources\Quotations;

use App\Actions\Admin\QuotationActions;
use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * Quotation draft form + "send" action, shared by the service request page
 * and the quotations list. Totals shown here are a preview; the server
 * recalculates everything when saving.
 */
class QuotationForm
{
    public const DEFAULT_TERMS = "50% mobilisation advance; balance on completion.\nPrices are in Sri Lankan Rupees.\nValid for the period stated above.";

    public static function schema(): array
    {
        return [
            Repeater::make('items')->label(__('Line items'))->required()->minItems(1)->defaultItems(1)->reorderable()
                ->columns(12)
                ->schema([
                    TextInput::make('description')->required()->maxLength(255)->columnSpan(6),
                    TextInput::make('quantity')->numeric()->required()->minValue(0.01)->default(1)->columnSpan(2)->live(onBlur: true),
                    TextInput::make('unit')->maxLength(30)->placeholder(__('site, day…'))->columnSpan(2),
                    TextInput::make('unit_price')->label(__('Unit price (LKR)'))->numeric()->required()->minValue(0)->columnSpan(2)->live(onBlur: true),
                ]),
            Grid::make(3)->schema([
                TextInput::make('tax_rate')->label(__('Tax rate (%)'))->numeric()->minValue(0)->maxValue(100)->default(0)->live(onBlur: true),
                DatePicker::make('valid_until')->label(__('Valid until'))->required()->minDate(today())->default(now()->addDays(30))->native(false),
                Text::make(function (Get $get) {
                    $subtotal = collect($get('items') ?? [])->sum(fn ($i) => (float) ($i['quantity'] ?? 0) * (float) ($i['unit_price'] ?? 0));
                    $total = $subtotal * (1 + (float) ($get('tax_rate') ?? 0) / 100);

                    return __('Total (preview): LKR :t', ['t' => number_format($total, 2)]);
                })->weight('bold'),
            ]),
            Textarea::make('terms')->label(__('Terms & conditions'))->rows(3)->default(self::DEFAULT_TERMS)->maxLength(3000),
        ];
    }

    public static function fill(Quotation $q): array
    {
        $tax = (float) $q->subtotal > 0 ? round((float) $q->tax_amount / (float) $q->subtotal * 100, 2) : 0;

        return [
            'items' => $q->items()->orderBy('sort')->get(['description', 'quantity', 'unit', 'unit_price'])->toArray(),
            'tax_rate' => $tax,
            'valid_until' => $q->valid_until?->toDateString(),
            'terms' => $q->terms,
        ];
    }

    public static function save(ServiceRequest $request, array $data, ?Quotation $draft = null): Quotation
    {
        return app(QuotationActions::class)->saveDraft(
            $request, $data['items'] ?? [], (float) ($data['tax_rate'] ?? 0), $data['valid_until'] ?? null, $data['terms'] ?? null, auth()->user(), $draft,
        );
    }

    public static function sendAction(string $name = 'send'): Action
    {
        return Action::make($name)->label(__('Approve & send'))->icon(Heroicon::OutlinedPaperAirplane)->color('success')
            ->visible(fn (Quotation $record) => $record->status === QuotationStatus::Draft && auth()->user()->can('quotations.approve'))
            ->requiresConfirmation()
            ->modalHeading(fn (Quotation $record) => __('Send :ref to the client?', ['ref' => $record->reference]))
            ->modalDescription(fn (Quotation $record) => __('A PDF will be generated and the client notified. Total: LKR :t. Earlier sent versions will be superseded.', ['t' => number_format($record->total, 2)]))
            ->action(function (Quotation $record) {
                app(QuotationActions::class)->send($record, auth()->user());
                Notification::make()->title(__('Quotation sent to the client'))->success()->send();
            });
    }

    public static function editAction(string $name = 'editDraft'): Action
    {
        return Action::make($name)->label(__('Edit draft'))->icon(Heroicon::OutlinedPencilSquare)
            ->visible(fn (Quotation $record) => $record->status === QuotationStatus::Draft && auth()->user()->can('quotations.manage'))
            ->modalWidth('5xl')
            ->fillForm(fn (Quotation $record) => self::fill($record))
            ->schema(self::schema())
            ->action(function (Quotation $record, array $data) {
                self::save($record->serviceRequest, $data, $record);
                Notification::make()->title(__('Draft saved'))->success()->send();
            });
    }
}
