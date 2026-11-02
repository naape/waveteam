<?php

namespace App\Filament\Resources\Assignments;

use App\Actions\Workforce\AssignmentActions;
use App\Enums\AssignmentStatus;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Resources\Applicants\ApplicantResource;
use App\Filament\Resources\Assignments\Pages\ListAssignments;
use App\Filament\Resources\ServiceRequests\ServiceRequestResource;
use App\Models\WorkforceAssignment;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AssignmentResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = WorkforceAssignment::class;

    protected static ?string $slug = 'assignments';

    protected static ?string $modelLabel = 'assignment';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Workforce';

    protected static ?int $navigationSort = 1;

    protected static array $permissions = ['view' => 'workforce.match'];

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['applicantProfile.currentPoliceClearance', 'serviceRequest.organization', 'assigner']);
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }
        $n = WorkforceAssignment::where('status', AssignmentStatus::Proposed)->count();

        return $n ? (string) $n : null;
    }

    /** One row action per allowed lifecycle move. */
    private static function transitionAction(AssignmentStatus $to, string $label, Heroicon $icon, string $color, bool $needsReason = false): Action
    {
        return Action::make('to_'.$to->value)->label($label)->icon($icon)->color($color)
            ->visible(fn (WorkforceAssignment $r) => auth()->user()->can('workforce.assign')
                && in_array($to->value, AssignmentActions::TRANSITIONS[$r->status->value], true))
            ->requiresConfirmation()
            ->schema($needsReason ? [Textarea::make('note')->label(__('Reason'))->required()->maxLength(500)] : [Textarea::make('note')->label(__('Note (optional)'))->maxLength(500)])
            ->action(function (WorkforceAssignment $r, array $data) use ($to) {
                app(AssignmentActions::class)->transition($r, $to, auth()->user(), $data['note'] ?? null);
                Notification::make()->title(__('Assignment :s', ['s' => strtolower($to->getLabel())]))->success()->send();
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('applicantProfile.full_name')->label(__('Person'))->searchable()
                    ->url(fn (WorkforceAssignment $r) => ApplicantResource::getUrl('view', ['record' => $r->applicant_profile_id])),
                TextColumn::make('role_title')->label(__('Role'))->searchable(),
                TextColumn::make('serviceRequest.reference')->label(__('Request'))->fontFamily('mono')->color('primary')
                    ->description(fn (WorkforceAssignment $r) => $r->serviceRequest->organization->name)
                    ->url(fn (WorkforceAssignment $r) => ServiceRequestResource::getUrl('view', ['record' => $r->serviceRequest])),
                TextColumn::make('start_date')->date()->sortable(),
                TextColumn::make('end_date')->date()->sortable()->placeholder(__('Open-ended')),
                TextColumn::make('pcc')->label(__('Clearance until'))
                    ->state(fn (WorkforceAssignment $r) => $r->serviceRequest->requires_police_clearance ? $r->applicantProfile->currentPoliceClearance?->renewalDate() : null)
                    ->date()->placeholder('—')
                    ->color(fn (WorkforceAssignment $r) => ($d = $r->applicantProfile->currentPoliceClearance?->renewalDate()) && $r->end_date && $d->lt($r->end_date) ? 'danger' : null),
                IconColumn::make('pcc_override')->label(__('Override'))->boolean()->trueColor('danger')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('start_date', 'desc')
            ->filters([
                SelectFilter::make('status')->options(AssignmentStatus::options())->multiple(),
                SelectFilter::make('service_request_id')->label(__('Request'))->relationship('serviceRequest', 'reference')->searchable(),
                Filter::make('current')->label(__('Current & upcoming'))->default()
                    ->query(fn (Builder $query) => $query->whereIn('status', [AssignmentStatus::Proposed, AssignmentStatus::Confirmed, AssignmentStatus::Active])),
            ])
            ->recordActions([
                ActionGroup::make([
                    static::transitionAction(AssignmentStatus::Confirmed, __('Confirm'), Heroicon::OutlinedCheck, 'info'),
                    static::transitionAction(AssignmentStatus::Active, __('Start (on site)'), Heroicon::OutlinedPlay, 'primary'),
                    static::transitionAction(AssignmentStatus::Completed, __('Complete'), Heroicon::OutlinedCheckBadge, 'success'),
                    static::transitionAction(AssignmentStatus::Released, __('Release'), Heroicon::OutlinedArrowUturnLeft, 'warning', true),
                    static::transitionAction(AssignmentStatus::Cancelled, __('Cancel'), Heroicon::OutlinedXMark, 'danger', true),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAssignments::route('/')];
    }
}
