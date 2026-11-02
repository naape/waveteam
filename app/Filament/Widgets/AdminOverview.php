<?php

namespace App\Filament\Widgets;

use App\Enums\ApplicationStatus;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\OrganizationStatus;
use App\Enums\PoliceClearanceStatus;
use App\Enums\QuotationStatus;
use App\Enums\ServiceRequestStatus;
use App\Enums\VerificationStatus;
use App\Filament\Resources\Applicants\ApplicantResource;
use App\Filament\Resources\DocumentReviews\DocumentReviewResource;
use App\Filament\Resources\JobApplications\JobApplicationResource;
use App\Filament\Resources\Organizations\OrganizationResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Resources\ServiceRequests\ServiceRequestResource;
use App\Models\ApplicantDocument;
use App\Models\ApplicantProfile;
use App\Models\JobApplication;
use App\Models\Organization;
use App\Models\PoliceClearanceRecord;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Headline KPIs. Each figure is shown only to staff allowed to see that area.
 */
class AdminOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '120s';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $u = auth()->user();
        $stats = [];

        if ($u->can('applicants.view')) {
            $total = ApplicantProfile::count();
            $month = ApplicantProfile::where('created_at', '>=', now()->startOfMonth())->count();
            $verified = ApplicantProfile::where('verification_status', VerificationStatus::Verified)->count();
            $stats[] = Stat::make(__('Registered applicants'), number_format($total))
                ->description(__(':n new this month · :v verified', ['n' => $month, 'v' => $verified]))
                ->icon(Heroicon::OutlinedUsers)->url(ApplicantResource::getUrl());
        }

        if ($u->can('documents.view')) {
            $docs = ApplicantDocument::where('is_current', true)->where('status', DocumentStatus::PendingVerification)
                ->whereNotIn('document_type', [DocumentType::PoliceClearance, DocumentType::Photo])->count();
            $stats[] = Stat::make(__('Documents to verify'), $docs)
                ->color($docs ? 'warning' : 'success')->icon(Heroicon::OutlinedDocumentCheck)
                ->url(DocumentReviewResource::getUrl());
        }

        if ($u->can('police_clearance.view') || $u->can('police_clearance.verify')) {
            $pending = PoliceClearanceRecord::pending()->whereNull('superseded_at')->count();
            $expiring = ApplicantProfile::query()->wherePccStatus(PoliceClearanceStatus::ExpiringSoon)->count();
            $expired = ApplicantProfile::query()->wherePccStatus(PoliceClearanceStatus::Expired)->count();
            $stats[] = Stat::make(__('Police clearance'), __(':n pending', ['n' => $pending]))
                ->description(__(':e expiring soon · :x expired', ['e' => $expiring, 'x' => $expired]))
                ->color($pending || $expired ? 'danger' : ($expiring ? 'warning' : 'success'))
                ->icon(Heroicon::OutlinedShieldCheck)
                ->url(\App\Filament\Resources\PoliceClearances\PoliceClearanceResource::getUrl());
        }

        if ($u->can('applications.view')) {
            $new = JobApplication::where('status', ApplicationStatus::Submitted)->count();
            $stats[] = Stat::make(__('New job applications'), $new)
                ->description(__(':n shortlisted', ['n' => JobApplication::where('status', ApplicationStatus::Shortlisted)->count()]))
                ->color($new ? 'info' : 'gray')->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->url(JobApplicationResource::getUrl());
        }

        if ($u->can('service_requests.view')) {
            $open = ServiceRequest::whereNotIn('status', [ServiceRequestStatus::Completed, ServiceRequestStatus::Cancelled])->count();
            $new = ServiceRequest::where('status', ServiceRequestStatus::Submitted)->count();
            $stats[] = Stat::make(__('Open service requests'), $open)
                ->description(__(':n new, awaiting review', ['n' => $new]))
                ->color($new ? 'warning' : 'primary')->icon(Heroicon::OutlinedClipboardDocumentList)
                ->url(ServiceRequestResource::getUrl());

            $active = ServiceRequest::whereIn('status', [ServiceRequestStatus::TeamAssigned, ServiceRequestStatus::InProgress])->count();
            $done = ServiceRequest::where('status', ServiceRequestStatus::Completed)->where('completed_at', '>=', now()->startOfYear())->count();
            $stats[] = Stat::make(__('Projects in progress'), $active)
                ->description(__(':n completed this year', ['n' => $done]))
                ->icon(Heroicon::OutlinedWrenchScrewdriver);
        }

        if ($u->can('quotations.view')) {
            $drafts = Quotation::where('status', QuotationStatus::Draft)->count();
            $awaiting = Quotation::where('status', QuotationStatus::Sent)->count();
            $stats[] = Stat::make(__('Quotations'), __(':n awaiting client', ['n' => $awaiting]))
                ->description(__(':d drafts to approve', ['d' => $drafts]))
                ->color($drafts ? 'warning' : 'gray')->icon(Heroicon::OutlinedDocumentCurrencyDollar)
                ->url(QuotationResource::getUrl());
        }

        if ($u->can('organizations.view')) {
            $pendingOrgs = Organization::where('status', OrganizationStatus::Pending)->count();
            $stats[] = Stat::make(__('Organisations'), Organization::count())
                ->description(__(':n awaiting verification', ['n' => $pendingOrgs]))
                ->color($pendingOrgs ? 'warning' : 'gray')->icon(Heroicon::OutlinedBuildingOffice2)
                ->url(OrganizationResource::getUrl('index', ['filters' => ['status' => ['value' => 'pending']]]));
        }

        return $stats;
    }
}
