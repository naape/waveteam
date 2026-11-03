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
                TextColumn::make('full_name')->label(__('Applicant'))->sortable()->weight('medium')
                    ->description(fn (ApplicantProfile $r) => collect([$r->user?->email, $r->contact_number])->filter()->implode(' · '))
                    // Name / email / phone search, plus exact NIC match via the blind index.
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn ($q) => $q
                        ->where('full_name', 'like', '%'.addcslashes($search, '%_\\').'%')
                        ->orWhere('contact_number', 'like', '%'.addcslashes(ltrim($search, '0+'), '%_\\').'%')
                        ->orWhereHas('user', fn ($u) => $u->where('email', 'like', '%'.addcslashes($search, '%_\\').'%'))
                        ->when(BlindIndex::isValidNic($search), fn ($q) => $q->orWhere('nic_hash', BlindIndex::nic($search))))),
                TextColumn::make('district.name_en')->label(__('District'))->sortable()->placeholder('—')->toggleable(),
                TextColumn::make('jobCategories.name')->label(__('Categories'))->badge()->color('gray')->limitList(1)->expandableLimitedList()->toggleable(),
                TextColumn::make('verification_status')->label(__('Verification'))->badge(),
                TextColumn::make('pcc')->label(__('Police clearance'))->badge()
                    ->state(fn (ApplicantProfile $r) => $r->policeClearanceStatus()),
                TextColumn::make('availability_status')->label(__('Availability'))->badge()->toggleable(),
                TextColumn::make('profile_completion')->label(__('Profile'))->suffix('%')->sortable()->toggleable()
                    ->color(fn ($state) => $state >= 80 ? 'success' : ($state >= 50 ? 'warning' : 'danger')),
                TextColumn::make('total_experience_years')->label(__('Exp. (yrs)'))->numeric(1)->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('applicant_status')->label(__('Status'))->badge()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label(__('Registered'))->date('j M Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->searchPlaceholder(__('Name, phone, NIC…'))
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
            ->recordActions([ViewAction::make()->iconButton()->tooltip(__('Open'))])
            ->paginated([25, 50, 100]);
    }

    /** The applicant page builds its own layout (ViewApplicant::infolist). */
    public static function infolist(Schema $schema): Schema
    {
        return $schema;
    }

    /** No separate relation-manager tab bar: the page embeds these in its Qualifications tab. */
    public static function getRelations(): array
    {
        return [];
    }

    /** Editable qualification sections shown in the applicant page's Qualifications tab. */
    public static function sectionManagers(): array
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
