<?php

namespace App\Filament\Resources\Applicants\Pages;

use App\Enums\AvailabilityStatus;
use App\Enums\PoliceClearanceStatus;
use App\Enums\VerificationStatus;
use App\Filament\Resources\Applicants\ApplicantResource;
use App\Models\ApplicantProfile;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListApplicants extends ListRecords
{
    protected static string $resource = ApplicantResource::class;

    /** Quick views for the most common admin tasks. */
    public function getTabs(): array
    {
        $count = fn (\Closure $scope) => $scope(ApplicantProfile::query())->count() ?: null;

        $toVerify = fn (Builder $q) => $q->where('verification_status', '!=', VerificationStatus::Verified);
        $pccReview = fn (Builder $q) => $q->wherePccStatus(PoliceClearanceStatus::PendingVerification);
        $pccAttention = fn (Builder $q) => $q->where(fn ($q) => $q
            ->wherePccStatus(PoliceClearanceStatus::ExpiringSoon)
            ->orWhere(fn ($q) => $q->wherePccStatus(PoliceClearanceStatus::Expired)));
        $available = fn (Builder $q) => $q->whereIn('availability_status', [AvailabilityStatus::Available, AvailabilityStatus::AvailableFrom]);

        return [
            'all' => Tab::make(__('All')),
            'to_verify' => Tab::make(__('To verify'))->modifyQueryUsing($toVerify)->badge($count($toVerify))->badgeColor('warning'),
            'pcc_review' => Tab::make(__('Clearance to review'))->modifyQueryUsing($pccReview)->badge($count($pccReview))->badgeColor('info'),
            'pcc_attention' => Tab::make(__('Clearance expiring / expired'))->modifyQueryUsing($pccAttention)->badge($count($pccAttention))->badgeColor('danger'),
            'available' => Tab::make(__('Available'))->modifyQueryUsing($available),
        ];
    }
}
