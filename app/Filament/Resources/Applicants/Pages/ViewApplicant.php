<?php

namespace App\Filament\Resources\Applicants\Pages;

use App\Actions\Admin\ApplicantAccountActions;
use App\Actions\Admin\ApplicantReview;
use App\Enums\ApplicantStatus;
use App\Enums\DocumentType;
use App\Enums\VerificationStatus;
use App\Filament\Actions\PasswordActions;
use App\Filament\Resources\Applicants\ApplicantResource;
use App\Models\ApplicantProfile;
use App\Models\District;
use App\Rules\PhoneNumberRule;
use App\Support\Audit;
use App\Support\BlindIndex;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
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

            $this->editDetailsAction($p),

            ActionGroup::make([
                PasswordActions::set(fn (ApplicantProfile $r) => collect([$r->user]), fn () => auth()->user()->can('applicants.update')),
                PasswordActions::resetLink(fn (ApplicantProfile $r) => collect([$r->user]), fn () => auth()->user()->can('applicants.update')),
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

    private function editDetailsAction(ApplicantProfile $p): Action
    {
        $canPii = auth()->user()->can('applicants.view_pii');

        return Action::make('editDetails')->label(__('Edit details'))->icon(Heroicon::OutlinedPencilSquare)->color('gray')
            ->visible(fn () => auth()->user()->can('applicants.update'))
            ->modalWidth('3xl')
            ->fillForm(fn () => [
                ...$p->only(ApplicantAccountActions::PROFILE_FIELDS),
                'date_of_birth' => $p->date_of_birth?->toDateString(),
                'email' => $p->user->email,
                'nic_number' => $canPii ? $p->nic_number : null,
            ])
            ->schema([
                Section::make(__('Identity & sign-in'))->columns(2)->schema([
                    TextInput::make('full_name')->label(__('Full name'))->required()->minLength(3)->maxLength(150),
                    TextInput::make('email')->label(__('Email (sign-in)'))->email()->required()->maxLength(190)
                        ->unique(\App\Models\User::class, 'email', ignorable: $p->user)
                        ->helperText(__('If changed, the applicant must confirm the new address from their inbox.')),
                    TextInput::make('nic_number')->label(__('NIC'))->maxLength(12)
                        ->visible($canPii)->required($canPii)
                        ->rule(fn () => function (string $attribute, $value, \Closure $fail) use ($p) {
                            if (! BlindIndex::isValidNic($value)) {
                                $fail(__('Enter a valid NIC (e.g. 199012345678 or 901234567V).'));
                            } elseif (ApplicantProfile::where('nic_hash', BlindIndex::nic($value))->whereKeyNot($p->id)->exists()) {
                                $fail(__('Another applicant is already registered with this NIC.'));
                            }
                        }),
                    TextInput::make('passport_number')->label(__('Passport'))->maxLength(20)->regex('/^[A-Za-z0-9]+$/')
                        ->visible($canPii),
                    DatePicker::make('date_of_birth')->required()
                        ->maxDate(now()->subYears(16))->minDate('1940-01-02')
                        ->rule(fn (\Filament\Schemas\Components\Utilities\Get $get) => function (string $attribute, $value, \Closure $fail) use ($get, $p, $canPii) {
                            $year = \App\Support\Nic::birthYear($canPii ? $get('nic_number') : $p->nic_number);
                            if ($year && $value && (int) date('Y', strtotime($value)) !== $year) {
                                $fail(__('Date of birth does not match the birth year in the NIC (:year).', ['year' => $year]));
                            }
                        }),
                    Select::make('gender')->options([
                        'male' => __('Male'), 'female' => __('Female'), 'other' => __('Other'), 'prefer_not_to_say' => __('Prefer not to say'),
                    ]),
                ]),
                Section::make(__('Contact'))->columns(2)->schema([
                    TextInput::make('contact_number')->label(__('Mobile'))->required()->maxLength(25)->rule(new PhoneNumberRule),
                    TextInput::make('whatsapp_number')->label(__('WhatsApp'))->maxLength(25)->rule(new PhoneNumberRule),
                    Select::make('district_id')->label(__('District'))->required()->searchable()
                        ->options(fn () => District::orderBy('name_en')->pluck('name_en', 'id')),
                    Textarea::make('permanent_address')->required()->maxLength(500)->rows(2),
                    Textarea::make('current_address')->maxLength(500)->rows(2),
                    TextInput::make('emergency_contact_name')->label(__('Emergency contact'))->required()->maxLength(150),
                    TextInput::make('emergency_contact_number')->label(__('Emergency number'))->required()->maxLength(25)->rule(new PhoneNumberRule),
                ]),
                Textarea::make('reason')->label(__('Reason for the change (audit log)'))->required()->maxLength(1000),
            ])
            ->action(function (array $data) use ($p, $canPii) {
                if (! $canPii) {
                    unset($data['nic_number'], $data['passport_number']);
                    $data['passport_number'] = $p->passport_number;
                }
                app(ApplicantAccountActions::class)->updateDetails($p, $data, auth()->user(), $data['reason']);
                $this->record->refresh();
                Notification::make()->title(__('Applicant details saved'))->success()->send();
            });
    }
}
