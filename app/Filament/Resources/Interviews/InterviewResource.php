<?php

namespace App\Filament\Resources\Interviews;

use App\Actions\Admin\RecruitmentActions;
use App\Enums\InterviewStatus;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Resources\Interviews\Pages\ListInterviews;
use App\Filament\Resources\JobApplications\JobApplicationResource;
use App\Models\Interview;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class InterviewResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = Interview::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Recruitment';

    protected static ?int $navigationSort = 3;

    protected static array $permissions = ['view' => 'interviews.manage'];

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['application.applicantProfile', 'application.vacancy', 'scheduler']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('scheduled_at')->label(__('When'))->dateTime('D j M Y, g:i A')->sortable()
                    ->color(fn (Interview $r) => $r->status === InterviewStatus::Scheduled && $r->scheduled_at->isPast() ? 'danger' : null),
                TextColumn::make('application.applicantProfile.full_name')->label(__('Applicant'))->searchable()
                    ->url(fn (Interview $r) => JobApplicationResource::getUrl('view', ['record' => $r->job_application_id])),
                TextColumn::make('application.vacancy.title')->label(__('Vacancy'))->limit(35),
                TextColumn::make('mode')->badge()->color('gray'),
                TextColumn::make('location_or_link')->label(__('Location'))->limit(30)->toggleable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('score')->placeholder('—')->toggleable(),
            ])
            ->defaultSort('scheduled_at')
            ->filters([
                Filter::make('upcoming')->label(__('Upcoming only'))->default()
                    ->query(fn (Builder $query) => $query->where('scheduled_at', '>=', today())),
                SelectFilter::make('status')->options(InterviewStatus::options()),
            ])
            ->recordActions([
                Action::make('outcome')->label(__('Record outcome'))->icon(Heroicon::OutlinedPencilSquare)
                    ->fillForm(fn (Interview $r) => ['status' => $r->status === InterviewStatus::Scheduled ? 'completed' : $r->status->value, 'score' => $r->score, 'outcome' => $r->outcome])
                    ->schema([
                        Select::make('status')->options([
                            InterviewStatus::Completed->value => InterviewStatus::Completed->getLabel(),
                            InterviewStatus::NoShow->value => InterviewStatus::NoShow->getLabel(),
                            InterviewStatus::Cancelled->value => InterviewStatus::Cancelled->getLabel(),
                        ])->required(),
                        TextInput::make('score')->numeric()->minValue(0)->maxValue(100)->suffix('/100'),
                        Textarea::make('outcome')->label(__('Notes / outcome'))->maxLength(2000),
                    ])
                    ->action(function (Interview $r, array $data) {
                        app(RecruitmentActions::class)->recordOutcome($r, InterviewStatus::from($data['status']), auth()->user(), filled($data['score']) ? (int) $data['score'] : null, $data['outcome'] ?? null);
                        Notification::make()->title(__('Outcome recorded'))->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListInterviews::route('/')];
    }
}
