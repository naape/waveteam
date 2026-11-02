<?php

namespace App\Actions\Admin;

use App\Actions\ServiceRequests\ChangeServiceRequestStatus;
use App\Enums\QuotationStatus;
use App\Enums\ServiceRequestStatus;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Notifications\TemplatedNotification;
use App\Support\Audit;
use App\Support\Documents\DocumentVault;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Quotation lifecycle on the WaveTeam side: draft → (approve &) send.
 * Amounts are always recalculated on the server from the line items.
 */
class QuotationActions
{
    public const QUOTABLE = [ServiceRequestStatus::UnderReview, ServiceRequestStatus::QuotationPreparation, ServiceRequestStatus::QuotationSent];

    public function __construct(
        private readonly ChangeServiceRequestStatus $changeStatus,
        private readonly DocumentVault $vault,
    ) {}

    /**
     * Create a new draft, or update an existing draft.
     *
     * @param  array<int, array{description:string, quantity:numeric, unit?:?string, unit_price:numeric}>  $items
     */
    public function saveDraft(ServiceRequest $request, array $items, float $taxRate, ?string $validUntil, ?string $terms, User $by, ?Quotation $draft = null): Quotation
    {
        if (! in_array($request->status, self::QUOTABLE, true)) {
            throw ValidationException::withMessages(['items' => __('A quotation can only be prepared while the request is under review or being quoted.')]);
        }
        if ($draft && $draft->status !== QuotationStatus::Draft) {
            throw ValidationException::withMessages(['items' => __('Only draft quotations can be edited.')]);
        }
        if (count($items) === 0) {
            throw ValidationException::withMessages(['items' => __('Add at least one line item.')]);
        }
        if ($taxRate < 0 || $taxRate > 100) {
            throw ValidationException::withMessages(['tax_rate' => __('Tax rate must be between 0 and 100%.')]);
        }

        $quotation = DB::transaction(function () use ($request, $items, $taxRate, $validUntil, $terms, $by, $draft) {
            $quotation = $draft ?? new Quotation([
                'service_request_id' => $request->id,
                'reference' => $this->nextReference(),
                'version' => $request->quotations()->count() + 1,
                'currency' => 'LKR',
                'prepared_by' => $by->id,
            ]);
            $quotation->forceFill(['status' => QuotationStatus::Draft, 'valid_until' => $validUntil, 'terms' => $terms])->save();

            $quotation->items()->delete();
            $subtotal = 0.0;
            foreach (array_values($items) as $i => $item) {
                $qty = round((float) $item['quantity'], 2);
                $price = round((float) $item['unit_price'], 2);
                $line = round($qty * $price, 2);
                $subtotal += $line;

                QuotationItem::create([
                    'quotation_id' => $quotation->id,
                    'description' => $item['description'],
                    'quantity' => $qty,
                    'unit' => $item['unit'] ?? null,
                    'unit_price' => $price,
                    'line_total' => $line,
                    'sort' => $i,
                ]);
            }

            $tax = round($subtotal * $taxRate / 100, 2);
            $quotation->forceFill(['subtotal' => $subtotal, 'tax_amount' => $tax, 'total' => $subtotal + $tax])->save();

            return $quotation;
        });

        if ($request->status === ServiceRequestStatus::UnderReview) {
            ($this->changeStatus)($request, ServiceRequestStatus::QuotationPreparation, $by, 'Quotation '.$quotation->reference.' drafted');
        }

        Audit::log($draft ? 'quotation.updated' : 'quotation.drafted', $quotation, new: ['total' => (string) $quotation->total]);

        return $quotation->fresh('items');
    }

    /** Generates the PDF, sends to the client and supersedes any earlier sent version. */
    public function send(Quotation $quotation, User $by): Quotation
    {
        if (! $by->can('quotations.approve')) {
            throw ValidationException::withMessages(['quotation' => __('You are not authorised to approve and send quotations.')]);
        }
        if ($quotation->status !== QuotationStatus::Draft || $quotation->items()->doesntExist()) {
            throw ValidationException::withMessages(['quotation' => __('Only a draft quotation with line items can be sent.')]);
        }
        if ($quotation->valid_until && $quotation->valid_until->isPast()) {
            throw ValidationException::withMessages(['quotation' => __('Set a validity date in the future before sending.')]);
        }

        $request = $quotation->serviceRequest()->with('organization')->firstOrFail();
        if (! in_array($request->status, self::QUOTABLE, true)) {
            throw ValidationException::withMessages(['quotation' => __('The request is no longer awaiting a quotation.')]);
        }

        $quotation->load('items');
        $pdf = Pdf::loadView('pdf.quotation', ['quotation' => $quotation, 'request' => $request, 'company' => config('portal.company')])
            ->setPaper('a4')->output();
        $file = $this->vault->storeContents($pdf, $quotation->reference.'.pdf', $by);

        DB::transaction(function () use ($quotation, $request, $by, $file) {
            $request->quotations()->where('status', QuotationStatus::Sent)->whereKeyNot($quotation->id)
                ->update(['status' => QuotationStatus::Superseded]);

            $quotation->forceFill([
                'status' => QuotationStatus::Sent,
                'sent_at' => now(),
                'approved_by' => $by->id,
                'pdf_file_id' => $file->id,
            ])->save();

            ($this->changeStatus)($request, ServiceRequestStatus::QuotationSent, $by, 'Quotation '.$quotation->reference.' sent');
        });

        Audit::log('quotation.sent', $quotation, new: ['total' => (string) $quotation->total, 'pdf' => $file->uuid]);

        Notification::send($request->organization->users, new TemplatedNotification('quotation.sent', [
            'quotation_reference' => $quotation->reference,
            'reference' => $request->reference,
        ], route('organization.requests.show', $request).'#quotations', __('Review quotation')));

        return $quotation;
    }

    private function nextReference(): string
    {
        $year = now()->year;
        $last = Quotation::where('reference', 'like', "QT-{$year}-%")->max('reference');

        return sprintf('QT-%d-%04d', $year, $last ? ((int) substr($last, -4)) + 1 : 1);
    }
}
