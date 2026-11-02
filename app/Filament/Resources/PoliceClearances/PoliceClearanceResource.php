<?php

namespace App\Filament\Resources\PoliceClearances;

use App\Actions\Compliance\PoliceClearanceVerification;
use App\Enums\PccVerificationStatus;
use App\Enums\PoliceClearanceStatus;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Resources\Applicants\ApplicantResource;
use App\Filament\Resources\PoliceClearances\Pages\ListPoliceClearances;
use App\Models\PoliceClearanceRecord;
use App\Support\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * Compliance queue for police clearance certificates: verify (setting the
 * applicable expiry/renewal date), reject, and adjust renewal dates.
 */
class PoliceClearanceResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = PoliceClearanceRecord::class;

    protected static ?string $slug = 'police-clearance';

    protected static ?string $modelLabel = 'police clearance certificate';

    protected static ?string $navigationLabel = 'Police clearance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Applicants';

    protected static ?int $navigationSort = 3;

    protected static array $permissions = ['view' => 'police_clearance.view'];

    public static function canViewAny(): bool
    {
        $u = auth()->user();

        return $u && ($u->can('police_clearance.view') || $u->can('police_clearance.verify'));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['applicantProfile.user', 'document', 'verifier']);
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }
        $n = PoliceClearanceRecord::pending()->whereNull('superseded_at')->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        $verify = fn () => app(PoliceClearanceVerification::class);
        $canVerify = fn () => auth()->user()->can('police_clearance.verify');
        $canOpen = fn () => auth()->user()->can('police_clearance.view');

        return $table
            ->columns([
                TextColumn::make('applicantProfile.full_name')->label(__('Applicant'))->searchable()
                    ->url(fn (PoliceClearanceRecord $r) => ApplicantResource::getUrl('view', ['record' => $r->applicant_profile_id, 'tab' => 'police-clearance::data::tab'])),
                TextColumn::make('issue_date')->date()->sortable(),
                TextColumn::make('issuing_authority')->label(__('Issued by'))->placeholder('—')->toggleable(),
                TextColumn::make('uploaded_at')->label(__('Uploaded'))->since()->sortable(),
                TextColumn::make('status')->label(__('Status'))->badge()->state(fn (PoliceClearanceRecord $r) => $r->superseded_at ? null : $r->displayStatus())
                    ->placeholder(__('Replaced')),
                TextColumn::make('renewal')->label(__('Renewal due'))->state(fn (PoliceClearanceRecord $r) => $r->renewalDate())->date()->placeholder('—'),
                TextColumn::make('verifier.name')->label(__('Verified by'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('uploaded_at')
            ->filters([
                SelectFilter::make('queue')->label(__('Show'))
                    ->options([
                        'pending' => __('Awaiting verification'),
                        'expiring' => __('In force — expiring soon'),
                        'expired' => __('In force — expired'),
                        'current' => __('All certificates in force'),
                        'rejected' => __('Rejected'),
                        'all' => __('Everything (incl. history)'),
                    ])
                    ->default('pending')
                    ->query(function (Builder $query, array $data) {
                        $today = today()->toDateString();
                        $soon = today()->addDays((int) Settings::get('pcc.expiring_soon_days'))->toDateString();
                        $due = 'COALESCE(next_renewal_due, valid_until)';
                        $inForce = fn ($q) => $q->where('verification_status', PccVerificationStatus::Approved)->whereNull('superseded_at');

                        return match ($data['value'] ?? 'pending') {
                            'pending' => $query->where('verification_status', PccVerificationStatus::PendingVerification)->whereNull('superseded_at'),
                            'expiring' => $inForce($query)->whereRaw("{$due} between ? and ?", [$today, $soon]),
                            'expired' => $inForce($query)->whereRaw("{$due} < ?", [$today]),
                            'current' => $inForce($query),
                            'rejected' => $query->where('verification_status', PccVerificationStatus::Rejected),
                            default => $query,
                        };
                    }),
                Filter::make('uploaded_recently')->label(__('Uploaded in the last 7 days'))
                    ->query(fn (Builder $query) => $query->where('uploaded_at', '>=', now()->subWeek())),
            ])
            ->recordActions([
                Action::make('open')->label(__('Open certificate'))->icon(Heroicon::OutlinedArrowTopRightOnSquare)->color('gray')
                    ->visible($canOpen)
                    ->url(fn (PoliceClearanceRecord $r) => route('documents.file', $r->applicant_document_id), shouldOpenInNewTab: true),

                Action::make('approve')->label(__('Verify'))->icon(Heroicon::OutlinedCheckBadge)->color('success')
                    ->visible(fn (PoliceClearanceRecord $r) => $canVerify() && $r->verification_status === PccVerificationStatus::PendingVerification && ! $r->superseded_at)
                    ->modalHeading(fn (PoliceClearanceRecord $r) => __('Verify certificate for :n', ['n' => $r->applicantProfile->full_name]))
                    ->fillForm(fn (PoliceClearanceRecord $r) => [
                        'valid_until' => max($r->suggestedValidUntil(), today()->addDay())->toDateString(),
                        'next_renewal_due' => null,
                    ])
                    ->schema(fn (PoliceClearanceRecord $r) => [
                        Text::make(__('Issued :d by :a. Open the certificate and confirm it is genuine, legible and issued to this applicant (name and NIC) before verifying.', [
                            'd' => $r->issue_date->format('j M Y'), 'a' => $r->issuing_authority ?: __('(not stated)'),
                        ])),
                        DatePicker::make('valid_until')->label(__('Valid until (expiry)'))->required()->native(false)
                            ->minDate(today())
                            ->helperText(__('Suggested: issue date + :m months. Change it if the certificate or the client requires a different period.', ['m' => Settings::get('pcc.default_validity_months')])),
                        DatePicker::make('next_renewal_due')->label(__('Renewal due (optional)'))->native(false)->minDate(today())
                            ->helperText(__('Leave empty to use the expiry date. Set earlier to ask for renewal sooner.')),
                        Textarea::make('notes')->label(__('Internal notes'))->maxLength(1000),
                    ])
                    ->action(function (PoliceClearanceRecord $r, array $data) use ($verify) {
                        $verify()->approve($r, auth()->user(), Carbon::parse($data['valid_until']),
                            filled($data['next_renewal_due']) ? Carbon::parse($data['next_renewal_due']) : null, $data['notes'] ?? null);
                        Notification::make()->title(__('Certificate verified; applicant notified'))->success()->send();
                    }),

                Action::make('reject')->label(__('Reject'))->icon(Heroicon::OutlinedXCircle)->color('danger')
                    ->visible(fn (PoliceClearanceRecord $r) => $canVerify() && $r->verification_status === PccVerificationStatus::PendingVerification && ! $r->superseded_at)
                    ->schema([Textarea::make('reason')->label(__('Reason (sent to the applicant)'))->required()->maxLength(500)
                        ->placeholder(__('e.g. Certificate is illegible / issued to a different person / older than 12 months'))])
                    ->action(function (PoliceClearanceRecord $r, array $data) use ($verify) {
                        $verify()->reject($r, auth()->user(), $data['reason']);
                        Notification::make()->title(__('Certificate rejected; applicant notified'))->success()->send();
                    }),

                Action::make('renewalDate')->label(__('Change renewal date'))->icon(Heroicon::OutlinedCalendar)->color('gray')
                    ->visible(fn (PoliceClearanceRecord $r) => $canVerify() && $r->verification_status === PccVerificationStatus::Approved && ! $r->superseded_at)
                    ->fillForm(fn (PoliceClearanceRecord $r) => ['next_renewal_due' => $r->renewalDate()?->toDateString()])
                    ->schema(fn (PoliceClearanceRecord $r) => [
                        DatePicker::make('next_renewal_due')->label(__('Renewal due'))->required()->native(false)->minDate(today())->maxDate($r->valid_until),
                        Textarea::make('reason')->label(__('Reason (audit log)'))->required()->maxLength(500),
                    ])
                    ->action(function (PoliceClearanceRecord $r, array $data) use ($verify) {
                        $verify()->changeRenewalDate($r, auth()->user(), Carbon::parse($data['next_renewal_due']), $data['reason']);
                        Notification::make()->title(__('Renewal date updated; reminders reset'))->success()->send();
                    }),
            ])
            ->emptyStateHeading(__('Nothing here'))
            ->emptyStateDescription(__('No certificates match this view.'));
    }

    public static function getPages(): array
    {
        return ['index' => ListPoliceClearances::route('/')];
    }
}
