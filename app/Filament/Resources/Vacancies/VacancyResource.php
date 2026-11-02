<?php

namespace App\Filament\Resources\Vacancies;

use App\Enums\EmploymentType;
use App\Enums\VacancyStatus;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Resources\JobApplications\JobApplicationResource;
use App\Filament\Resources\Vacancies\Pages\CreateVacancy;
use App\Filament\Resources\Vacancies\Pages\EditVacancy;
use App\Filament\Resources\Vacancies\Pages\ListVacancies;
use App\Models\JobVacancy;
use App\Support\Audit;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class VacancyResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = JobVacancy::class;

    protected static ?string $slug = 'vacancies';

    protected static ?string $modelLabel = 'vacancy';

    protected static ?string $pluralModelLabel = 'vacancies';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static string|UnitEnum|null $navigationGroup = 'Recruitment';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    protected static array $permissions = ['view' => 'vacancies.manage', 'create' => 'vacancies.manage', 'edit' => 'vacancies.manage'];

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('applications')->with(['jobCategory', 'district']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Vacancy'))->columns(2)->columnSpanFull()->schema([
                TextInput::make('title')->required()->maxLength(200)->columnSpan(2),
                Select::make('job_category_id')->label(__('Job category'))->relationship('jobCategory', 'name', fn ($query) => $query->where('is_active', true)->orderBy('sort'))->required()->preload(),
                Select::make('employment_type')->options(EmploymentType::options())->required()->default('contract'),
                Textarea::make('description')->required()->rows(6)->maxLength(10000)->columnSpan(2)
                    ->helperText(__('Plain text. Line breaks are kept.')),
                Textarea::make('requirements')->rows(4)->maxLength(5000)->columnSpan(2),
            ]),
            Section::make(__('Location & conditions'))->columns(3)->columnSpanFull()->schema([
                Select::make('district_id')->label(__('Main district'))->relationship('district', 'name_en')->searchable()->preload(),
                Select::make('districts')->label(__('Other districts'))->relationship('districts', 'name_en')->multiple()->preload(),
                TextInput::make('location_text')->label(__('Location description'))->maxLength(200),
                TextInput::make('positions')->numeric()->minValue(1)->maxValue(500)->default(1)->required(),
                TextInput::make('min_experience_years')->label(__('Minimum experience (years)'))->numeric()->minValue(0)->maxValue(40)->default(0),
                DatePicker::make('closing_date')->minDate(today())->native(false),
                Toggle::make('requires_police_clearance')->label(__('Police clearance required'))->default(true)->inline(false),
                Select::make('status')->options(VacancyStatus::options())->default(VacancyStatus::Draft->value)->required()
                    ->helperText(__('Only “Published” vacancies are visible to applicants.')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable()->description(fn (JobVacancy $r) => $r->jobCategory?->name),
                TextColumn::make('location_text')->label(__('Location'))->state(fn (JobVacancy $r) => $r->location_text ?: $r->district?->name_en)->toggleable(),
                TextColumn::make('positions')->numeric(),
                TextColumn::make('applications_count')->label(__('Applications'))->numeric()->sortable()
                    ->url(fn (JobVacancy $r) => JobApplicationResource::getUrl('index', ['filters' => ['job_vacancy_id' => ['value' => $r->id]]])),
                TextColumn::make('closing_date')->date()->sortable()->placeholder('—')
                    ->color(fn (JobVacancy $r) => $r->closing_date?->isPast() ? 'danger' : null),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(VacancyStatus::options()),
                SelectFilter::make('job_category_id')->label(__('Category'))->relationship('jobCategory', 'name'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('publish')->label(__('Publish'))->icon(Heroicon::OutlinedGlobeAlt)->color('success')
                    ->visible(fn (JobVacancy $r) => $r->status !== VacancyStatus::Published)
                    ->requiresConfirmation()
                    ->action(function (JobVacancy $r) {
                        abort_if($r->closing_date?->isPast(), 422, __('Set a future closing date first.'));
                        $r->update(['status' => VacancyStatus::Published, 'published_at' => $r->published_at ?? now()]);
                        Audit::log('vacancy.published', $r);
                        Notification::make()->title(__('Vacancy published'))->success()->send();
                    }),
                Action::make('close')->label(__('Close'))->icon(Heroicon::OutlinedLockClosed)->color('warning')
                    ->visible(fn (JobVacancy $r) => $r->status === VacancyStatus::Published)
                    ->requiresConfirmation()
                    ->action(function (JobVacancy $r) {
                        $r->update(['status' => VacancyStatus::Closed]);
                        Audit::log('vacancy.closed', $r);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVacancies::route('/'),
            'create' => CreateVacancy::route('/create'),
            'edit' => EditVacancy::route('/{record}/edit'),
        ];
    }
}
