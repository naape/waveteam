<?php

namespace App\Filament\Resources\JobApplications;

use App\Actions\Admin\RecruitmentActions;
use App\Enums\ApplicationStatus;
use App\Enums\InterviewMode;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Resources\Applicants\ApplicantResource;
use App\Filament\Resources\JobApplications\Pages\ListJobApplications;
use App\Filament\Resources\JobApplications\Pages\ViewJobApplication;
use App\Models\JobApplication;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use UnitEnum;

class JobApplicationResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = JobApplication::class;

    protected static ?string $slug = 'applications';

    protected static ?string $modelLabel = 'application';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Recruitment';

    protected static ?int $navigationSort = 2;

    protected static array $permissions = ['view' => 'applications.view'];

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['vacancy', 'applicantProfile.user', 'applicantProfile.currentPoliceClearance', 'cvDocument']);
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }
        $n = JobApplication::where('status', ApplicationStatus::Submitted)->count();

        return $n ? (string) $n : null;
    }

    /** Shared "change status" form + handler (row, bulk and page actions). */
    public static function statusAction(string $name = 'changeStatus'): Action
    {
        return Action::make($name)->label(__('Change status'))->icon(Heroicon::OutlinedArrowPath)
            ->visible(fn () => auth()->user()->can('applications.manage'))
            ->fillForm(fn (JobApplication $record) => ['status' => $record->status->value])
            ->schema([
                Select::make('status')->options(collect(ApplicationStatus::cases())
                    ->reject(fn ($s) => $s === ApplicationStatus::InterviewScheduled) // use "Schedule interview"
                    ->mapWithKeys(fn ($s) => [$s->value => $s->getLabel()]))->required(),
                Textarea::make('note')->label(__('Internal note (optional)'))->maxLength(1000),
                Toggle::make('notify')->label(__('Email the applicant'))->default(true),
            ])
            ->action(function (JobApplication $record, array $data) {
                app(RecruitmentActions::class)->changeStatus($record, ApplicationStatus::from($data['status']), auth()->user(), $data['note'] ?? null, (bool) $data['notify']);
                Notification::make()->title(__('Status updated'))->success()->send();
            });
    }

    public static function interviewAction(): Action
    {
        return Action::make('scheduleInterview')->label(__('Schedule interview'))->icon(Heroicon::OutlinedCalendarDays)->color('info')
            ->visible(fn (JobApplication $record) => auth()->user()->can('interviews.manage')
                && ! in_array($record->status, [ApplicationStatus::Rejected, ApplicationStatus::Selected], true))
            ->schema([
                DateTimePicker::make('scheduled_at')->label(__('Date & time'))->required()->seconds(false)->minDate(now())->native(false),
                Select::make('mode')->options(InterviewMode::options())->default('in_person')->required(),
                TextInput::make('location')->label(__('Location / video link'))->maxLength(255),
                Textarea::make('notes')->label(__('Internal notes'))->maxLength(1000),
            ])
            ->action(function (JobApplication $record, array $data) {
                app(RecruitmentActions::class)->scheduleInterview($record, Carbon::parse($data['scheduled_at']), InterviewMode::from($data['mode']), $data['location'] ?? null, auth()->user(), $data['notes'] ?? null);
                Notification::make()->title(__('Interview scheduled; invitation sent'))->success()->send();
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('applicantProfile.full_name')->label(__('Applicant'))->searchable()
                    ->description(fn (JobApplication $r) => $r->applicantProfile?->user?->email),
                TextColumn::make('vacancy.title')->label(__('Vacancy'))->searchable()->limit(40),
                TextColumn::make('applicantProfile.total_experience_years')->label(__('Exp.'))->suffix(' '.__('yrs')),
                TextColumn::make('pcc')->label(__('Police clearance'))->badge()->state(fn (JobApplication $r) => $r->applicantProfile->policeClearanceStatus()),
                TextColumn::make('submitted_at')->label(__('Applied'))->since()->sortable(),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->filters([
                SelectFilter::make('job_vacancy_id')->label(__('Vacancy'))->relationship('vacancy', 'title')->searchable()->preload(),
                SelectFilter::make('status')->options(ApplicationStatus::options())->multiple(),
            ])
            ->recordActions([ViewAction::make(), static::statusAction(), static::interviewAction()])
            ->toolbarActions([
                BulkAction::make('bulkStatus')->label(__('Change status'))->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn () => auth()->user()->can('applications.manage'))
                    ->schema([
                        Select::make('status')->options(collect(ApplicationStatus::cases())
                            ->reject(fn ($s) => $s === ApplicationStatus::InterviewScheduled)
                            ->mapWithKeys(fn ($s) => [$s->value => $s->getLabel()]))->required(),
                        Toggle::make('notify')->label(__('Email the applicants'))->default(true),
                    ])
                    ->action(function (Collection $records, array $data) {
                        $records->each(fn ($r) => app(RecruitmentActions::class)->changeStatus($r, ApplicationStatus::from($data['status']), auth()->user(), null, (bool) $data['notify']));
                        Notification::make()->title(__(':n applications updated', ['n' => $records->count()]))->success()->send();
                    }),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Application'))->columns(3)->columnSpanFull()->schema([
                TextEntry::make('applicantProfile.full_name')->label(__('Applicant'))
                    ->url(fn (JobApplication $r) => ApplicantResource::canViewAny() ? ApplicantResource::getUrl('view', ['record' => $r->applicant_profile_id]) : null)
                    ->color('primary'),
                TextEntry::make('vacancy.title')->label(__('Vacancy')),
                TextEntry::make('status')->badge(),
                TextEntry::make('submitted_at')->dateTime(),
                TextEntry::make('applicantProfile.total_experience_years')->label(__('Experience'))->suffix(' '.__('years')),
                TextEntry::make('pcc')->label(__('Police clearance'))->badge()->state(fn (JobApplication $r) => $r->applicantProfile->policeClearanceStatus()),
                TextEntry::make('cv')->label(__('CV submitted'))->state(fn (JobApplication $r) => $r->cvDocument ? __('Open CV (v:v)', ['v' => $r->cvDocument->version]) : null)
                    ->url(fn (JobApplication $r) => $r->cvDocument && auth()->user()->can('view', $r->cvDocument) ? route('documents.file', $r->cvDocument) : null, shouldOpenInNewTab: true)
                    ->color('primary')->placeholder('—'),
                TextEntry::make('cover_note')->label(__('Message from applicant'))->columnSpan(2)->placeholder('—'),
            ]),
            Section::make(__('Interviews'))->columnSpanFull()->schema([
                RepeatableEntry::make('interviews')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('scheduled_at')->hiddenLabel()->dateTime('D j M Y, g:i A'),
                    TextEntry::make('mode')->hiddenLabel()->badge()->color('gray'),
                    TextEntry::make('location_or_link')->hiddenLabel()->placeholder('—'),
                    TextEntry::make('status')->hiddenLabel()->badge(),
                ])->placeholder(__('No interviews')),
            ]),
            Section::make(__('History'))->columnSpanFull()->schema([
                RepeatableEntry::make('statusHistory')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('created_at')->hiddenLabel()->dateTime(),
                    TextEntry::make('to_status')->hiddenLabel()->badge()->formatStateUsing(fn (string $state) => ApplicationStatus::tryFrom($state)?->getLabel() ?? $state),
                    TextEntry::make('changer.name')->hiddenLabel()->placeholder('—'),
                    TextEntry::make('note')->hiddenLabel()->placeholder(''),
                ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJobApplications::route('/'),
            'view' => ViewJobApplication::route('/{record}'),
        ];
    }
}
