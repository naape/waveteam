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
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
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
            ])->label(__('More'))->icon(Heroicon::OutlinedEllipsisVertical)->button()->color('gray'),
        ];
    }

    public function getTitle(): string
    {
        return $this->record->full_name;
    }

    /**
     * Page layout: an at-a-glance summary, then tabs. Each section has its own
     * Edit button right where the information is shown.
     */
    public function infolist(Schema $schema): Schema
    {
        /** @var ApplicantProfile $p */
        $p = $this->record;
        $canPii = auth()->user()->can('applicants.view_pii');
        $sections = collect(ApplicantResource::sectionManagers())
            ->filter(fn ($rm) => $rm::canViewForRecord($p, static::class));

        return $schema->components([
            // ---- Summary ---------------------------------------------------------
            Section::make()->key('summary')->columnSpanFull()->schema([
                Grid::make(['default' => 1, 'md' => 3])->schema([
                    TextEntry::make('contact')->hiddenLabel()->columnSpan(['md' => 1])
                        ->state(fn () => collect([$p->user->email, $p->contact_number, $p->district?->name_en])->filter()->implode(' · '))
                        ->icon(Heroicon::OutlinedEnvelope)->color('gray')
                        ->helperText(fn () => __('Registered :d', ['d' => $p->created_at->format('j M Y')])),
                    Grid::make(['default' => 2, 'sm' => 4])->columnSpan(['md' => 2])->schema([
                        TextEntry::make('verification_status')->label(__('Verification'))->badge()
                            ->helperText(fn () => $p->verified_at ? __(':who, :d', ['who' => $p->verifier?->name, 'd' => $p->verified_at->format('j M Y')]) : null),
                        TextEntry::make('pcc_summary')->label(__('Police clearance'))->badge()
                            ->state(fn () => $p->policeClearanceStatus())
                            ->helperText(fn () => ($d = $p->currentPoliceClearance?->renewalDate()) ? __('Renewal :d', ['d' => $d->format('j M Y')]) : null),
                        TextEntry::make('availability_status')->label(__('Availability'))->badge()
                            ->helperText(fn () => $p->available_from ? __('from :d', ['d' => $p->available_from->format('j M Y')]) : null),
                        TextEntry::make('applicant_status')->label(__('Status'))->badge(),
                    ]),
                ]),
                ViewEntry::make('profile_completion')->label(__('Profile completion'))->view('filament.infolists.completion'),
            ]),

            Tabs::make()->key('tabs')->columnSpanFull()->persistTabInQueryString()->tabs([
                // ---- Personal details ------------------------------------------
                Tab::make(__('Personal details'))->icon(Heroicon::OutlinedUser)->schema([
                    Section::make(__('Personal details'))->key('personal')
                        ->headerActions([$this->editDetailsAction()])
                        ->columns(['default' => 1, 'md' => 3])->schema([
                            TextEntry::make('full_name')->label(__('Full name')),
                            TextEntry::make('nic')->label(__('NIC'))
                                ->state(fn () => $canPii ? $p->nic_number : $p->maskedNic())
                                ->helperText($canPii ? null : __('Masked — requires “view PII” permission'))
                                ->placeholder('—')->copyable($canPii),
                            TextEntry::make('passport')->label(__('Passport'))
                                ->state(fn () => $p->passport_number ? ($canPii ? $p->passport_number : '••••'.substr($p->passport_number, -3)) : null)
                                ->placeholder('—'),
                            TextEntry::make('date_of_birth')->label(__('Date of birth'))->date('j M Y')
                                ->helperText(fn () => $p->date_of_birth ? __(':n years', ['n' => $p->date_of_birth->age]) : null)->placeholder('—'),
                            TextEntry::make('gender')->formatStateUsing(fn (?string $state) => $state ? ucfirst(str_replace('_', ' ', $state)) : null)->placeholder('—'),
                            TextEntry::make('district.name_en')->label(__('District'))->placeholder('—'),
                            TextEntry::make('user.email')->label(__('Email'))->copyable(),
                            TextEntry::make('contact_number')->label(__('Mobile'))->copyable()->placeholder('—'),
                            TextEntry::make('whatsapp_number')->label(__('WhatsApp'))->placeholder('—'),
                            TextEntry::make('permanent_address')->label(__('Permanent address'))->placeholder('—'),
                            TextEntry::make('current_address')->label(__('Current address'))->placeholder('—'),
                            TextEntry::make('emergency')->label(__('Emergency contact'))
                                ->state(fn () => trim(($p->emergency_contact_name ?? '').($p->emergency_contact_number ? ' · '.$p->emergency_contact_number : ''), ' ·') ?: null)
                                ->placeholder('—'),
                        ]),
                ]),

                // ---- Skills & preferences --------------------------------------
                Tab::make(__('Skills & preferences'))->icon(Heroicon::OutlinedWrenchScrewdriver)->schema([
                    Section::make(__('Skills & job categories'))->key('skills')
                        ->headerActions([$this->editSkillsAction()])
                        ->schema([
                            Grid::make(['default' => 1, 'md' => 3])->schema([
                                TextEntry::make('jobCategories.name')->label(__('Job categories'))->badge()->columnSpan(['md' => 2])->placeholder(__('None chosen')),
                                TextEntry::make('total_experience_years')->label(__('Total experience'))
                                    ->formatStateUsing(fn ($state) => __(':n years', ['n' => rtrim(rtrim(number_format((float) $state, 1), '0'), '.')])),
                            ]),
                            RepeatableEntry::make('skills')->label(__('Skills'))->columns(3)->schema([
                                TextEntry::make('name')->hiddenLabel()->weight('medium'),
                                TextEntry::make('pivot.years')->hiddenLabel()->formatStateUsing(fn ($state) => __(':n yrs', ['n' => rtrim(rtrim((string) $state, '0'), '.') ?: '0'])),
                                TextEntry::make('pivot.level')->hiddenLabel()->badge()->color('gray')->formatStateUsing(fn ($state) => ucfirst((string) $state)),
                            ])->placeholder(__('No skills added')),
                        ]),
                    Section::make(__('Work preferences'))->key('preferences')
                        ->headerActions([$this->editPreferencesAction()])
                        ->columns(['default' => 1, 'md' => 3])->schema([
                            TextEntry::make('preferredDistricts.name_en')->label(__('Willing to work in'))->badge()->color('gray')->columnSpan(['md' => 2])->placeholder(__('Not chosen')),
                            TextEntry::make('availability_status')->label(__('Availability'))->badge()
                                ->helperText(fn () => $p->available_from ? __('from :d', ['d' => $p->available_from->format('j M Y')]) : null),
                        ]),
                ]),

                // ---- Qualifications (editable tables) --------------------------
                Tab::make(__('Qualifications'))->icon(Heroicon::OutlinedAcademicCap)
                    ->badge(fn () => $p->educations->count() + $p->certifications->count() ?: null)
                    ->schema($sections->map(fn ($rm) => Livewire::make($rm, ['ownerRecord' => $p, 'pageClass' => static::class])
                        ->key('rm-'.class_basename($rm)))->values()->all()),

                // ---- Documents ---------------------------------------------------
                Tab::make(__('Documents'))->icon(Heroicon::OutlinedFolder)->schema([
                    RepeatableEntry::make('documents')->hiddenLabel()->columns(['default' => 2, 'md' => 5])
                        ->state(fn () => $p->documents()->with('file')->orderByDesc('is_current')->latest()->get())
                        ->schema([
                            TextEntry::make('document_type')->hiddenLabel()->badge()->color('gray'),
                            TextEntry::make('title')->hiddenLabel()->state(fn ($record) => $record->title ?: $record->file?->original_name),
                            TextEntry::make('version')->hiddenLabel()->prefix('v')->suffix(fn ($record) => $record->is_current ? '' : ' ('.__('old').')'),
                            TextEntry::make('status')->hiddenLabel()->badge(),
                            TextEntry::make('open')->hiddenLabel()->state(__('Open'))->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                                ->color('primary')
                                ->url(fn ($record) => auth()->user()->can('view', $record) ? route('documents.file', $record) : null, shouldOpenInNewTab: true)
                                ->visible(fn ($record) => auth()->user()->can('view', $record)),
                        ])->placeholder(__('No documents uploaded')),
                ]),

                // ---- Police clearance --------------------------------------------
                Tab::make(__('Police clearance'))->icon(Heroicon::OutlinedShieldCheck)->schema([
                    Section::make(__('Police clearance'))->key('pcc')
                        ->headerActions([$this->uploadPccAction(), $this->removePccAction()])
                        ->schema([
                            TextEntry::make('pcc_status')->label(__('Current status'))->badge()->state(fn () => $p->policeClearanceStatus())
                                ->helperText(fn () => ($d = $p->currentPoliceClearance?->renewalDate()) ? __('Renewal due :d', ['d' => $d->format('j M Y')]) : null),
                            RepeatableEntry::make('policeClearanceRecords')->label(__('History'))->columns(['default' => 2, 'md' => 5])->schema([
                                TextEntry::make('issue_date')->label(__('Issued'))->date('j M Y'),
                                TextEntry::make('uploaded_at')->label(__('Uploaded'))->date('j M Y'),
                                TextEntry::make('status')->label(__('Status'))->badge()->state(fn ($record) => $record->displayStatus()),
                                TextEntry::make('valid_until')->label(__('Valid until'))->state(fn ($record) => $record->renewalDate())->date('j M Y')->placeholder('—'),
                                TextEntry::make('superseded_at')->label(__('Replaced / removed'))
                                    ->state(fn ($record) => $record->removed_at ? __('Removed :d', ['d' => $record->removed_at->format('j M Y')]) : $record->superseded_at?->format('j M Y'))
                                    ->color(fn ($record) => $record->removed_at ? 'danger' : null)
                                    ->helperText(fn ($record) => $record->removal_reason)
                                    ->placeholder('—'),
                            ])->placeholder(__('No certificates uploaded')),
                        ]),
                ]),

                // ---- Applications ----------------------------------------------
                Tab::make(__('Applications'))->icon(Heroicon::OutlinedClipboardDocumentCheck)
                    ->badge(fn () => $p->jobApplications->count() ?: null)
                    ->schema([
                        RepeatableEntry::make('jobApplications')->hiddenLabel()->columns(3)->schema([
                            TextEntry::make('vacancy.title')->hiddenLabel()->weight('medium'),
                            TextEntry::make('submitted_at')->hiddenLabel()->date('j M Y'),
                            TextEntry::make('status')->hiddenLabel()->badge(),
                        ])->placeholder(__('No applications')),
                    ]),
            ]),
        ]);
    }

    public function editDetailsAction(): Action
    {
        /** @var ApplicantProfile $p */
        $p = $this->record;
        $canPii = auth()->user()->can('applicants.view_pii');

        return Action::make('editDetails')->label(__('Edit'))->icon(Heroicon::OutlinedPencilSquare)->link()
            ->modalHeading(__('Edit personal details'))
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

    public function uploadPccAction(): Action
    {
        /** @var ApplicantProfile $p */
        $p = $this->record;
        $canVerify = auth()->user()->can('police_clearance.verify');

        return Action::make('uploadPcc')->label(__('Upload certificate'))->icon(Heroicon::OutlinedArrowUpTray)->size('sm')
            ->visible(fn () => auth()->user()->can('applicants.update') || $canVerify)
            ->modalHeading(__('Upload a police clearance certificate'))
            ->modalDescription(__('Uploads on the applicant’s behalf (e.g. a certificate handed in at the office). It is not valid until approved.'))
            ->schema([
                FileUpload::make('file')->label(__('Certificate (PDF, JPG or PNG)'))->required()->storeFiles(false)
                    ->maxSize((int) \App\Support\Settings::get('uploads.max_kb'))
                    ->acceptedFileTypes(\App\Support\Documents\DocumentVault::PERSONAL_TYPES),
                DatePicker::make('issue_date')->label(__('Issue date'))->required()->maxDate(today())->live()
                    ->afterStateUpdated(fn (?string $state, \Filament\Schemas\Components\Utilities\Set $set) => $set('valid_until', $state
                        ? \Illuminate\Support\Carbon::parse($state)->addMonths((int) \App\Support\Settings::get('pcc.default_validity_months'))->toDateString()
                        : null)),
                TextInput::make('certificate_number')->label(__('Certificate number (optional)'))->maxLength(60),
                TextInput::make('issuing_authority')->label(__('Issued by (optional)'))->maxLength(150)->placeholder(__('Sri Lanka Police')),
                Toggle::make('approve_now')->label(__('I have checked the original — approve it now'))->live()->visible($canVerify),
                DatePicker::make('valid_until')->label(__('Valid until'))->visible(fn ($get) => $canVerify && $get('approve_now'))
                    ->required(fn ($get) => (bool) $get('approve_now'))
                    ->helperText(__('Default: issue date + :m months.', ['m' => \App\Support\Settings::get('pcc.default_validity_months')])),
                DatePicker::make('renewal_due')->label(__('Renewal due (optional)'))->visible(fn ($get) => $canVerify && $get('approve_now'))
                    ->helperText(__('Leave empty to use the valid-until date.')),
            ])
            ->action(function (array $data) use ($p) {
                $approve = ($data['approve_now'] ?? false) && filled($data['valid_until'] ?? null) ? [
                    'valid_until' => \Illuminate\Support\Carbon::parse($data['valid_until']),
                    'renewal_due' => filled($data['renewal_due'] ?? null) ? \Illuminate\Support\Carbon::parse($data['renewal_due']) : null,
                    'notes' => __('Uploaded and approved by staff'),
                ] : null;

                $record = app(\App\Actions\Admin\ApplicantRecordsActions::class)->uploadPoliceClearance(
                    $p, $data['file'], \Illuminate\Support\Carbon::parse($data['issue_date']), auth()->user(),
                    $data['certificate_number'] ?? null, $data['issuing_authority'] ?? null, $approve,
                );
                $this->record->refresh();
                Notification::make()->success()->title($record->verification_status === \App\Enums\PccVerificationStatus::Approved
                    ? __('Certificate uploaded and approved') : __('Certificate uploaded — awaiting verification'))->send();
            });
    }

    public function removePccAction(): Action
    {
        /** @var ApplicantProfile $p */
        $p = $this->record;
        $options = fn () => $p->policeClearanceRecords()->whereNull('removed_at')->get()->mapWithKeys(fn ($r) => [
            $r->id => __('Issued :i · uploaded :u · :s', [
                'i' => $r->issue_date->format('j M Y'),
                'u' => $r->uploaded_at->format('j M Y'),
                's' => $r->superseded_at ? __('replaced') : $r->displayStatus()->getLabel(),
            ]).((int) $p->current_police_clearance_id === (int) $r->id ? ' — '.__('IN FORCE') : ''),
        ]);

        return Action::make('removePcc')->label(__('Remove'))->icon(Heroicon::OutlinedNoSymbol)->color('danger')->outlined()->size('sm')
            ->visible(fn () => auth()->user()->can('police_clearance.verify') && $p->policeClearanceRecords()->whereNull('removed_at')->exists())
            ->modalHeading(__('Remove a police clearance certificate'))
            ->modalDescription(__('Use this for a wrong or invalid upload. The certificate stops counting immediately (if it was in force, the applicant will show as having no valid clearance), but it is kept in the history with your reason — it is not deleted.'))
            ->schema([
                Select::make('record_id')->label(__('Certificate'))->options($options)->required()
                    ->default(fn () => $options()->keys()->first()),
                Textarea::make('reason')->label(__('Reason (kept in history and audit log)'))->required()->maxLength(1000),
            ])
            ->action(function (array $data) use ($p) {
                $record = $p->policeClearanceRecords()->whereNull('removed_at')->findOrFail($data['record_id']);
                app(\App\Actions\Admin\ApplicantRecordsActions::class)->removePoliceClearance($record, auth()->user(), $data['reason']);
                $this->record->refresh();
                Notification::make()->title(__('Certificate removed'))->success()->send();
            });
    }

    public function editSkillsAction(): Action
    {
        /** @var ApplicantProfile $p */
        $p = $this->record;
        $skillOptions = fn () => \App\Models\JobCategory::query()->orderBy('sort')
            ->with(['skills' => fn ($q) => $q->where('is_active', true)->orderBy('name')])->get()
            ->filter(fn ($c) => $c->skills->isNotEmpty())
            ->mapWithKeys(fn ($c) => [$c->name => $c->skills->pluck('name', 'id')->all()])->all();

        return Action::make('editSkills')->label(__('Edit'))->icon(Heroicon::OutlinedPencilSquare)->link()
            ->visible(fn () => auth()->user()->can('applicants.update'))
            ->modalHeading(__('Skills & job categories'))
            ->modalWidth('3xl')
            ->fillForm(fn () => [
                'job_categories' => $p->jobCategories()->pluck('job_categories.id')->all(),
                'total_experience_years' => (float) $p->total_experience_years,
                'skills' => $p->skills()->get()->map(fn ($s) => ['skill_id' => $s->id, 'years' => (float) $s->pivot->years, 'level' => $s->pivot->level])->all(),
            ])
            ->schema([
                Select::make('job_categories')->label(__('Job categories'))->multiple()->required()->minItems(1)
                    ->options(fn () => \App\Models\JobCategory::where('is_active', true)->orderBy('sort')->pluck('name', 'id')),
                TextInput::make('total_experience_years')->label(__('Total experience (years)'))->required()->numeric()->minValue(0)->maxValue(60),
                \Filament\Forms\Components\Repeater::make('skills')->label(__('Skills'))
                    ->schema([
                        Select::make('skill_id')->label(__('Skill'))->options($skillOptions)->required()->searchable()
                            ->disableOptionsWhenSelectedInSiblingRepeaterItems()->columnSpan(2),
                        TextInput::make('years')->label(__('Years'))->numeric()->minValue(0)->maxValue(60)->default(0),
                        Select::make('level')->options(['basic' => __('Basic'), 'intermediate' => __('Intermediate'), 'expert' => __('Expert')])
                            ->default('intermediate')->required()->selectablePlaceholder(false),
                    ])
                    ->columns(4)->addActionLabel(__('Add skill'))->defaultItems(0)->reorderable(false)->maxItems(60),
                Textarea::make('reason')->label(__('Reason for the change (audit log)'))->required()->maxLength(1000),
            ])
            ->action(function (array $data) use ($p) {
                app(\App\Actions\Admin\ApplicantRecordsActions::class)->updateSkills($p, $data, auth()->user(), $data['reason']);
                $this->record->refresh()->load(['jobCategories', 'skills']);
                Notification::make()->title(__('Skills and job categories saved'))->success()->send();
            });
    }

    public function editPreferencesAction(): Action
    {
        /** @var ApplicantProfile $p */
        $p = $this->record;
        $engaged = fn () => $p->availability_status === \App\Enums\AvailabilityStatus::Engaged;
        $statuses = collect([\App\Enums\AvailabilityStatus::Available, \App\Enums\AvailabilityStatus::AvailableFrom, \App\Enums\AvailabilityStatus::Unavailable])
            ->mapWithKeys(fn ($s) => [$s->value => $s->getLabel()])->all();

        return Action::make('editPreferences')->label(__('Edit'))->icon(Heroicon::OutlinedPencilSquare)->link()
            ->visible(fn () => auth()->user()->can('applicants.update'))
            ->modalHeading(__('Work preferences & availability'))
            ->fillForm(fn () => [
                'districts' => $p->preferredDistricts()->pluck('districts.id')->all(),
                'availability_status' => $p->availability_status?->value,
                'available_from' => $p->available_from?->toDateString(),
            ])
            ->schema([
                Select::make('districts')->label(__('Districts willing to work in'))->multiple()->required()->minItems(1)->searchable()
                    ->options(fn () => District::orderBy('province')->orderBy('name_en')->get()->groupBy('province')
                        ->map(fn ($ds) => $ds->pluck('name_en', 'id')->all())->all()),
                Select::make('availability_status')->label(__('Availability'))->options($statuses)->live()
                    ->required(fn () => ! $engaged())->disabled($engaged)
                    ->helperText(fn () => $engaged() ? __('Engaged on a project — availability is set by the assignment and changes when it ends.') : null),
                DatePicker::make('available_from')->label(__('Available from'))->minDate(today())
                    ->visible(fn ($get) => ! $engaged() && $get('availability_status') === \App\Enums\AvailabilityStatus::AvailableFrom->value)
                    ->required(fn ($get) => $get('availability_status') === \App\Enums\AvailabilityStatus::AvailableFrom->value),
                Textarea::make('reason')->label(__('Reason for the change (audit log)'))->required()->maxLength(1000),
            ])
            ->action(function (array $data) use ($p) {
                app(\App\Actions\Admin\ApplicantRecordsActions::class)->updatePreferences($p, $data, auth()->user(), $data['reason']);
                $this->record->refresh()->load('preferredDistricts');
                Notification::make()->title(__('Work preferences saved'))->success()->send();
            });
    }
}
