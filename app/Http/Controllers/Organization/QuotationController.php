<?php

namespace App\Http\Controllers\Organization;

use App\Actions\ServiceRequests\ClientRequestActions;
use App\Enums\QuotationStatus;
use App\Http\Controllers\Controller;
use App\Models\Quotation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class QuotationController extends Controller
{
    public function respond(Request $request, Quotation $quotation, ClientRequestActions $actions): RedirectResponse
    {
        // Drafts are invisible to clients: treat as not found.
        abort_if($quotation->status === QuotationStatus::Draft, 404);
        Gate::authorize('act', $quotation->serviceRequest);

        $data = $request->validate([
            'decision' => ['required', 'in:accept,reject'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'confirm' => ['accepted_if:decision,accept'],
        ], ['confirm.accepted_if' => __('Please confirm you are authorised to accept this quotation.')]);

        $accept = $data['decision'] === 'accept';
        $actions->respondToQuotation($quotation, $request->user(), $accept, $data['comment'] ?? null);

        return redirect()->to(route('organization.requests.show', $quotation->serviceRequest).'#quotations')
            ->with('status', $accept
                ? __('Quotation :ref accepted. WaveTeam will now assign your team.', ['ref' => $quotation->reference])
                : __('Quotation :ref rejected. WaveTeam will be in touch.', ['ref' => $quotation->reference]));
    }
}
