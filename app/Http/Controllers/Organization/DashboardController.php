<?php

namespace App\Http\Controllers\Organization;

use App\Enums\QuotationStatus;
use App\Enums\ServiceRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Models\ServiceRequestMessage;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $membership = $user->organizationMembership()->with('organization')->firstOrFail();
        $organization = $membership->organization;
        $requests = $organization->serviceRequests();

        $closed = [ServiceRequestStatus::Completed, ServiceRequestStatus::Cancelled];

        return view('organization.dashboard', [
            'membership' => $membership,
            'organization' => $organization,
            'stats' => [
                'open' => (clone $requests)->whereNotIn('status', $closed)->count(),
                'quotations' => Quotation::whereHas('serviceRequest', fn ($q) => $q->withoutTrashed()->where('organization_id', $organization->id))
                    ->where('status', QuotationStatus::Sent)->count(),
                'active' => (clone $requests)->whereIn('status', [ServiceRequestStatus::TeamAssigned, ServiceRequestStatus::InProgress])->count(),
                'completed' => (clone $requests)->where('status', ServiceRequestStatus::Completed)->count(),
            ],
            'recent' => (clone $requests)->with('serviceCategory')->latest()->limit(6)->get(),
            'unreadMessages' => ServiceRequestMessage::query()
                ->whereHas('serviceRequest', fn ($q) => $q->where('organization_id', $organization->id))
                ->where('is_internal', false)->whereNull('read_at')
                ->whereHas('sender', fn ($q) => $q->where('user_type', 'staff'))
                ->with('serviceRequest:id,reference,title')
                ->latest()->limit(5)->get(),
            'notifications' => $user->notifications()->latest()->limit(5)->get(),
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }
}
