<?php

namespace App\Filament\Resources\ServiceRequests\Pages;

use App\Actions\Workforce\AssignmentActions;
use App\Enums\AssignmentStatus;
use App\Filament\Resources\ServiceRequests\ServiceRequestResource;
use App\Models\ApplicantProfile;
use App\Models\ServiceRequest;
use App\Support\Compliance\PoliceClearanceEligibility;
use App\Support\Workforce\MatchResult;
use App\Support\Workforce\WorkforceMatcher;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Workforce matching for one service request: ranked, explained candidates
 * and one-click assignment (eligibility re-checked on assign).
 */
class MatchWorkforce extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ServiceRequestResource::class;

    protected string $view = 'filament.match-workforce';

    public bool $strictExperience = true;

    public bool $districtOnly = false;

    public static function canAccess(array $parameters = []): bool
    {
        return (bool) auth()->user()?->can('workforce.match');
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->record->load(['skills', 'district', 'serviceCategory', 'organization']);
    }

    public function getTitle(): string
    {
        return __('Find workforce — :ref', ['ref' => $this->record->reference]);
    }

    public function getBreadcrumbs(): array
    {
        return [
            ServiceRequestResource::getUrl() => __('Service Requests'),
            ServiceRequestResource::getUrl('view', ['record' => $this->record]) => $this->record->reference,
            __('Find workforce'),
        ];
    }

    /** @return Collection<int, MatchResult> */
    public function getResults(): Collection
    {
        return app(WorkforceMatcher::class)->match($this->record, [
            'strict_experience' => $this->strictExperience,
            'district_only' => $this->districtOnly,
        ]);
    }

    public function getTeam(): Collection
    {
        return $this->record->assignments()
            ->whereNotIn('status', [AssignmentStatus::Cancelled, AssignmentStatus::Released])
            ->with('applicantProfile:id,full_name')
            ->orderBy('start_date')->get();
    }

    public function getPeriod(): array
    {
        return WorkforceMatcher::period($this->record);
    }

    public function canAssign(): bool
    {
        return auth()->user()->can('workforce.assign')
            && in_array($this->record->status, AssignmentActions::ASSIGNABLE_REQUEST, true);
    }

    /** Rendered per candidate in the view: {{ ($this->assignAction)(['profile' => $id]) }} */
    public function assignAction(): Action
    {
        /** @var ServiceRequest $r */
        $r = $this->record;
        [$start, $end] = $this->getPeriod();

        return Action::make('assign')->label(__('Assign'))->icon(Heroicon::OutlinedUserPlus)->size('sm')
            ->visible(fn () => $this->canAssign())
            ->modalHeading(fn (array $arguments) => __('Assign :n', ['n' => ApplicantProfile::find($arguments['profile'] ?? 0)?->full_name]))
            ->fillForm(fn () => [
                'role_title' => $r->skills->first()?->name ?? $r->serviceCategory?->name,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
            ])
            ->schema([
                TextInput::make('role_title')->label(__('Role on this project'))->required()->maxLength(150),
                DatePicker::make('start_date')->required()->native(false),
                DatePicker::make('end_date')->native(false)->afterOrEqual('start_date'),
                Textarea::make('notes')->label(__('Notes'))->maxLength(1000),
            ])
            ->action(function (array $data, array $arguments) use ($r) {
                $profile = ApplicantProfile::findOrFail($arguments['profile']);
                app(AssignmentActions::class)->assign($r, $profile, $data['role_title'], Carbon::parse($data['start_date']),
                    filled($data['end_date']) ? Carbon::parse($data['end_date']) : null, auth()->user(), notes: $data['notes'] ?? null);
                Notification::make()->title(__(':n proposed for this project; they have been notified.', ['n' => $profile->full_name]))->success()->send();
            });
    }

    /**
     * Exceptional assignment of someone NOT eligible for a clearance-required
     * site. Super Admin only (workforce.override_pcc), reason recorded.
     */
    public function overrideAction(): Action
    {
        /** @var ServiceRequest $r */
        $r = $this->record;
        [$start, $end] = $this->getPeriod();

        return Action::make('override')->label(__('Assign with clearance override'))->icon(Heroicon::OutlinedExclamationTriangle)->color('danger')->size('sm')
            ->visible(fn () => $this->canAssign() && $r->requires_police_clearance && auth()->user()->can('workforce.override_pcc'))
            ->schema(fn () => [
                Text::make(__('This person does not have valid police clearance for this period. The override and your reason are recorded in the audit log.'))->color('danger'),
                TextInput::make('nic')->label(__('Applicant NIC'))->required()
                    ->helperText(__('Enter the NIC of the verified applicant to assign.')),
                TextInput::make('role_title')->required()->maxLength(150),
                DatePicker::make('start_date')->required()->native(false)->default($start->toDateString()),
                DatePicker::make('end_date')->native(false)->default($end->toDateString()),
                Textarea::make('reason')->label(__('Reason for override'))->required()->maxLength(1000),
            ])
            ->action(function (array $data) use ($r) {
                $profile = ApplicantProfile::findByNic($data['nic']);
                if (! $profile) {
                    Notification::make()->title(__('No applicant with that NIC.'))->danger()->send();

                    return;
                }
                app(AssignmentActions::class)->assign($r, $profile, $data['role_title'], Carbon::parse($data['start_date']),
                    filled($data['end_date']) ? Carbon::parse($data['end_date']) : null, auth()->user(), $data['reason']);
                Notification::make()->title(__('Assigned with clearance override (audited).'))->warning()->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')->label(__('Back to request'))->color('gray')->icon(Heroicon::OutlinedArrowLeft)
                ->url(ServiceRequestResource::getUrl('view', ['record' => $this->record])),
            $this->overrideAction(),
        ];
    }

    public function eligibilityNote(ApplicantProfile $p): ?string
    {
        [, $end] = $this->getPeriod();

        return $this->record->requires_police_clearance ? PoliceClearanceEligibility::reason($p, $end) : null;
    }
}
