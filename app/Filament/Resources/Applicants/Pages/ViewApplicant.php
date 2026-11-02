<?php

namespace App\Filament\Resources\Applicants\Pages;

use App\Actions\Admin\ApplicantReview;
use App\Enums\ApplicantStatus;
use App\Enums\DocumentType;
use App\Enums\VerificationStatus;
use App\Filament\Resources\Applicants\ApplicantResource;
use App\Models\ApplicantProfile;
use App\Support\Audit;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewApplicant extends ViewRecord
{
    protected static string $resource = ApplicantResource::class;

    /** Eager-load everything the page shows (strict mode forbids lazy loading). */
    protected function resolveRecord(int|string $key): \Illuminate\Database\Eloquent\Model
    {
        return parent::resolveRecord($key)->load(['user', 'district', 'verifier', 'jobCategories', 'preferredDistricts', 'skills', 'educations', 'certifications', 'employments', 'languages', 'drivingLicences', 'militaryServices', 'policeClearanceRecords', 'currentPoliceClearance', 'jobApplications.vacancy']);
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // Viewing an applicant's full record is itself auditable.
        Audit::log('applicant.viewed', $this->record);
    }

    protected function getHeaderActions(): array
    {
        /** @var ApplicantProfile $p */
        $p = $this->record;
        $review = app(ApplicantReview::class);

        $cv = $p->documents()->where('document_type', DocumentType::Cv)->where('is_current', true)->first();

        return [
            Action::make('cv')->label(__('Open CV'))->icon(Heroicon::OutlinedDocumentText)->color('gray')
                ->url($cv ? route('documents.file', $cv) : null, shouldOpenInNewTab: true)
                ->visible($cv !== null && auth()->user()->can('view', $cv)),

            Action::make('verify')->label(__('Verify profile'))->icon(Heroicon::OutlinedCheckBadge)->color('success')
                ->visible(fn () => auth()->user()->can('applicants.verify') && $p->verification_status !== VerificationStatus::Verified)
                ->requiresConfirmation()
                ->modalDescription(__('Confirm that you have checked this applicant’s identity, qualifications and documents.'))
                ->action(function () use ($p, $review) {
                    $review->setVerification($p, VerificationStatus::Verified, auth()->user());
                    Notification::make()->title(__('Profile verified'))->success()->send();
                }),

            ActionGroup::make([
                Action::make('unverify')->label(__('Mark as rejected / unverified'))->icon(Heroicon::OutlinedXCircle)->color('danger')
                    ->visible(fn () => auth()->user()->can('applicants.verify'))
                    ->schema([
                        Select::make('status')->options([
                            VerificationStatus::Rejected->value => VerificationStatus::Rejected->getLabel(),
                            VerificationStatus::Unverified->value => VerificationStatus::Unverified->getLabel(),
                        ])->required(),
                        Textarea::make('note')->label(__('Reason'))->required()->maxLength(1000),
                    ])
                    ->action(function (array $data) use ($p, $review) {
                        $review->setVerification($p, VerificationStatus::from($data['status']), auth()->user(), $data['note']);
                        Notification::make()->title(__('Verification updated'))->success()->send();
                    }),
                Action::make('status')->label(__('Change applicant status'))->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                    ->visible(fn () => auth()->user()->can('applicants.change_status'))
                    ->fillForm(['status' => $p->applicant_status->value])
                    ->schema([
                        Select::make('status')->options(ApplicantStatus::options())->required(),
                        Textarea::make('reason')->label(__('Reason (audit log)'))->required()->maxLength(1000),
                    ])
                    ->action(function (array $data) use ($p, $review) {
                        $review->setStatus($p, ApplicantStatus::from($data['status']), auth()->user(), $data['reason']);
                        Notification::make()->title(__('Applicant status updated'))->success()->send();
                    }),
            ])->label(__('More'))->button()->color('gray'),
        ];
    }
}
