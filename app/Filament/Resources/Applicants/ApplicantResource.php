<?php

namespace App\Filament\Resources\Applicants;

use App\Enums\ApplicantStatus;
use App\Enums\AvailabilityStatus;
use App\Enums\PoliceClearanceStatus;
use App\Enums\VerificationStatus;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Resources\Applicants\Pages\ListApplicants;
use App\Filament\Resources\Applicants\Pages\ViewApplicant;
use App\Models\ApplicantProfile;
use App\Support\BlindIndex;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ApplicantResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = ApplicantProfile::class;

    protected static ?string $slug = 'applicants';

    protected static ?string $modelLabel = 'applicant';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Applicants';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'full_name';

    protected static array $permissions = ['view' => 'applicants.view'];

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'district', 'jobCategories', 'currentPoliceClearance']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('full_name')->label(__('Name'))->sortable()
                    ->description(fn (ApplicantProfile $r) => $r->user?->email)
                    // Name / email search, plus exact NIC match via the blind index.
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn ($q) => $q
                        ->where('full_name', 'like', '%'.addcslashes($search, '%_\\').'%')
                        ->orWhereHas('user', fn ($u) => $u->where('email', 'like', '%'.addcslashes($search, '%_\\').'%'))
                        ->when(BlindIndex::isValidNic($search), fn ($q) => $q->orWhere('nic_hash', BlindIndex::nic($search))))),
                TextColumn::make('district.name_en')->label(__('District'))->sortable()->toggleable(),
                TextColumn::make('jobCategories.name')->label(__('Categories'))->badge()->color('gray')->limitList(2)->toggleable(),
                TextColumn::make('total_experience_years')->label(__('Exp. (yrs)'))->numeric(1)->sortable(),
                TextColumn::make('profile_completion')->label(__('Profile'))->suffix('%')->sortable(),
                TextColumn::make('verification_status')->label(__('Verification'))->badge(),
                TextColumn::make('pcc')->label(__('Police clearance'))->badge()
                    ->state(fn (ApplicantProfile $r) => $r->policeClearanceStatus()),
                TextColumn::make('applicant_status')->label(__('Status'))->badge()->toggleable(),
                TextColumn::make('availability_status')->label(__('Availability'))->badge()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label(__('Registered'))->date()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('job_category')->label(__('Job category'))
                    ->relationship('jobCategories', 'name')->multiple()->preload(),
                SelectFilter::make('district_id')->label(__('District'))->relationship('district', 'name_en')->searchable()->preload(),
                SelectFilter::make('preferred_district')->label(__('Willing to work in'))
                    ->relationship('preferredDistricts', 'name_en')->searchable()->preload(),
                Filter::make('experience')
                    ->schema([TextInput::make('min_years')->label(__('Minimum experience (years)'))->numeric()->minValue(0)])
                    ->query(fn (Builder $query, array $data) => $query->when(filled($data['min_years'] ?? null), fn ($query) => $query->where('total_experience_years', '>=', (float) $data['min_years'])))
                    ->indicateUsing(fn (array $data) => filled($data['min_years'] ?? null) ? __(':y+ years', ['y' => $data['min_years']]) : null),
                SelectFilter::make('verification_status')->options(VerificationStatus::options()),
                SelectFilter::make('pcc_status')->label(__('Police clearance'))
                    ->options(PoliceClearanceStatus::options())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn ($q, $v) => $q->wherePccStatus($v))),
                SelectFilter::make('applicant_status')->options(ApplicantStatus::options()),
                SelectFilter::make('availability_status')->options(AvailabilityStatus::options()),
            ])
            ->filtersFormColumns(2)
            ->recordActions([ViewAction::make()])
            ->paginated([25, 50, 100]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()->columnSpanFull()->persistTabInQueryString()->tabs([
                Tab::make(__('Profile'))->icon(Heroicon::OutlinedUser)->schema([
                    Section::make()->columns(3)->schema([
                        TextEntry::make('full_name')->label(__('Full name')),
                        TextEntry::make('nic')->label(__('NIC'))
                            ->state(fn (ApplicantProfile $r) => auth()->user()->can('applicants.view_pii') ? $r->nic_number : $r->maskedNic())
                            ->helperText(fn () => auth()->user()->can('applicants.view_pii') ? null : __('Masked — requires “view PII” permission'))
                            ->placeholder('—'),
                        TextEntry::make('passport')->label(__('Passport'))
                            ->state(fn (ApplicantProfile $r) => $r->passport_number ? (auth()->user()->can('applicants.view_pii') ? $r->passport_number : '••••'.substr($r->passport_number, -3)) : null)
                            ->placeholder('—'),
                        TextEntry::make('date_of_birth')->date()->placeholder('—'),
                        TextEntry::make('gender')->formatStateUsing(fn (?string $state) => $state ? ucfirst(str_replace('_', ' ', $state)) : null)->placeholder('—'),
                        TextEntry::make('district.name_en')->label(__('District'))->placeholder('—'),
                        TextEntry::make('user.email')->label(__('Email'))->copyable(),
                        TextEntry::make('contact_number')->label(__('Mobile'))->placeholder('—'),
                        TextEntry::make('whatsapp_number')->label(__('WhatsApp'))->placeholder('—'),
                        TextEntry::make('permanent_address')->columnSpan(2)->placeholder('—'),
                        TextEntry::make('current_address')->placeholder('—'),
                        TextEntry::make('emergency_contact_name')->label(__('Emergency contact'))->placeholder('—'),
                        TextEntry::make('emergency_contact_number')->label(__('Emergency number'))->placeholder('—'),
                    ]),
                    Section::make(__('Status'))->columns(4)->schema([
                        TextEntry::make('verification_status')->badge(),
                        TextEntry::make('applicant_status')->badge(),
                        TextEntry::make('availability_status')->badge()
                            ->helperText(fn (ApplicantProfile $r) => $r->available_from ? __('from :d', ['d' => $r->available_from->format('j M Y')]) : null),
                        TextEntry::make('profile_completion')->suffix('%'),
                        TextEntry::make('verifier.name')->label(__('Verified by'))->placeholder('—'),
                        TextEntry::make('verified_at')->dateTime()->placeholder('—'),
                        TextEntry::make('preferredDistricts.name_en')->label(__('Willing to work in'))->badge()->color('gray')->columnSpan(2)->placeholder('—'),
                    ]),
                ]),

                Tab::make(__('Qualifications & experience'))->icon(Heroicon::OutlinedAcademicCap)->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('jobCategories.name')->label(__('Job categories'))->badge()->placeholder('—'),
                        TextEntry::make('total_experience_years')->label(__('Total experience'))->suffix(' '.__('years')),
                    ]),
                    RepeatableEntry::make('skills')->label(__('Skills'))->columns(3)->schema([
                        TextEntry::make('name')->hiddenLabel(),
                        TextEntry::make('pivot.years')->hiddenLabel()->suffix(' '.__('yrs')),
                        TextEntry::make('pivot.level')->hiddenLabel()->badge()->color('gray'),
                    ])->placeholder(__('None')),
                    TextEntry::make('sections_hint')->hiddenLabel()->columnSpanFull()->color('gray')
                        ->state(__('Education, certifications, employment, languages, driving licences and military service are listed below the tabs.')),
                ]),

                Tab::make(__('Documents'))->icon(Heroicon::OutlinedFolder)->schema([
                    RepeatableEntry::make('documents')->hiddenLabel()->columns(5)
                        ->state(fn (ApplicantProfile $r) => $r->documents()->with('file')->orderByDesc('is_current')->latest()->get())
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

                Tab::make(__('Police clearance'))->icon(Heroicon::OutlinedShieldCheck)->schema([
                    TextEntry::make('pcc_status')->label(__('Current status'))->badge()->state(fn (ApplicantProfile $r) => $r->policeClearanceStatus()),
                    RepeatableEntry::make('policeClearanceRecords')->label(__('History'))->columns(5)->schema([
                        TextEntry::make('issue_date')->label(__('Issued'))->date(),
                        TextEntry::make('uploaded_at')->label(__('Uploaded'))->date(),
                        TextEntry::make('status')->label(__('Status'))->badge()->state(fn ($record) => $record->displayStatus()),
                        TextEntry::make('valid_until')->label(__('Valid until'))->state(fn ($record) => $record->renewalDate())->date()->placeholder('—'),
                        TextEntry::make('superseded_at')->label(__('Replaced / removed'))
                            ->state(fn ($record) => $record->removed_at ? __('Removed :d', ['d' => $record->removed_at->format('j M Y')]) : $record->superseded_at?->format('j M Y'))
                            ->color(fn ($record) => $record->removed_at ? 'danger' : null)
                            ->helperText(fn ($record) => $record->removal_reason)
                            ->placeholder('—'),
                    ])->placeholder(__('No certificates uploaded')),
                ]),

                Tab::make(__('Applications'))->icon(Heroicon::OutlinedClipboardDocumentCheck)->schema([
                    RepeatableEntry::make('jobApplications')->hiddenLabel()->columns(3)->schema([
                        TextEntry::make('vacancy.title')->hiddenLabel()->weight('medium'),
                        TextEntry::make('submitted_at')->hiddenLabel()->date(),
                        TextEntry::make('status')->hiddenLabel()->badge(),
                    ])->placeholder(__('No applications')),
                ]),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\EducationRelationManager::class,
            RelationManagers\CertificationsRelationManager::class,
            RelationManagers\EmploymentRelationManager::class,
            RelationManagers\LanguagesRelationManager::class,
            RelationManagers\DrivingLicencesRelationManager::class,
            RelationManagers\MilitaryServiceRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApplicants::route('/'),
            'view' => ViewApplicant::route('/{record}'),
        ];
    }
}
