<?php

namespace App\Filament\Resources\ServiceRequests;

use App\Enums\ServiceRequestStatus;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Resources\Organizations\OrganizationResource;
use App\Filament\Resources\ServiceRequests\Pages\ListServiceRequests;
use App\Filament\Resources\ServiceRequests\Pages\ViewServiceRequest;
use App\Models\ServiceRequest;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ServiceRequestResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = ServiceRequest::class;

    protected static ?string $slug = 'service-requests';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Clients & Services';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static array $permissions = ['view' => 'service_requests.view'];

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['organization', 'serviceCategory', 'manager', 'district']);
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }
        $n = ServiceRequest::where('status', ServiceRequestStatus::Submitted)->count();

        return $n ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->fontFamily('mono')->searchable()->sortable()->weight('medium'),
                TextColumn::make('title')->searchable()->limit(45)->description(fn (ServiceRequest $r) => $r->serviceCategory?->name),
                TextColumn::make('organization.name')->label(__('Organisation'))->searchable()->limit(30),
                TextColumn::make('required_headcount')->label(__('People'))->numeric(),
                TextColumn::make('district.name_en')->label(__('District'))->toggleable(),
                TextColumn::make('start_date')->date()->placeholder('—')->sortable()->toggleable(),
                TextColumn::make('manager.name')->label(__('Manager'))->placeholder(__('Unassigned'))->toggleable(),
                IconColumn::make('requires_police_clearance')->label(__('PCC'))->boolean()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->badge(),
                TextColumn::make('created_at')->label(__('Submitted'))->since()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(ServiceRequestStatus::options())->multiple(),
                SelectFilter::make('service_category_id')->label(__('Category'))->relationship('serviceCategory', 'name'),
                SelectFilter::make('organization_id')->label(__('Organisation'))->relationship('organization', 'name')->searchable(),
                SelectFilter::make('assigned_manager_id')->label(__('Manager'))->relationship('manager', 'name', fn ($query) => $query->staff()),
                Filter::make('mine')->label(__('Assigned to me'))->query(fn (Builder $query) => $query->where('assigned_manager_id', auth()->id())),
                Filter::make('open')->label(__('Open only'))->default()
                    ->query(fn (Builder $query) => $query->whereNotIn('status', [ServiceRequestStatus::Completed, ServiceRequestStatus::Cancelled])),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Grid::make(1)->columnSpan(2)->schema([
                Section::make()->columns(3)->schema([
                    TextEntry::make('title')->columnSpan(2)->weight('bold')->size('lg'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('serviceCategory.name')->label(__('Category')),
                    TextEntry::make('organization.name')->label(__('Organisation'))->color('primary')
                        ->url(fn (ServiceRequest $r) => OrganizationResource::canViewAny() ? OrganizationResource::getUrl('view', ['record' => $r->organization_id]) : null)
                        ->helperText(fn (ServiceRequest $r) => $r->organization->status->getLabel()),
                    TextEntry::make('submitter.name')->label(__('Submitted by'))->helperText(fn (ServiceRequest $r) => $r->submitter?->email),
                    TextEntry::make('description')->columnSpanFull()->prose()->formatStateUsing(fn (string $state) => nl2br(e($state)))->html(),
                    TextEntry::make('required_qualifications')->columnSpanFull()->placeholder('—'),
                    TextEntry::make('special_requirements')->columnSpanFull()->placeholder('—'),
                ]),
                Section::make(__('Quotations'))->schema([
                    RepeatableEntry::make('quotations')->hiddenLabel()->columns(5)
                        ->state(fn (ServiceRequest $r) => $r->quotations()->with('pdf')->latest()->get())
                        ->schema([
                            TextEntry::make('reference')->hiddenLabel()->fontFamily('mono')->suffix(fn ($record) => ' · v'.$record->version),
                            TextEntry::make('total')->hiddenLabel()->money('LKR'),
                            TextEntry::make('status')->hiddenLabel()->badge(),
                            TextEntry::make('valid_until')->hiddenLabel()->date()->prefix(__('valid to ')),
                            TextEntry::make('pdf')->hiddenLabel()->state(fn ($record) => $record->pdf_file_id ? 'PDF' : null)
                                ->url(fn ($record) => $record->pdf ? route('files.show', [$record->pdf, 'download' => 1]) : null)
                                ->icon(Heroicon::OutlinedArrowDownTray)->color('primary')->placeholder(''),
                            TextEntry::make('client_comment')->hiddenLabel()->columnSpanFull()->prefix(__('Client: '))->visible(fn ($record) => filled($record->client_comment)),
                        ])->placeholder(__('No quotations yet')),
                ]),
                Section::make(__('Team'))->schema([
                    RepeatableEntry::make('assignments')->hiddenLabel()->columns(4)
                        ->state(fn (ServiceRequest $r) => $r->assignments()->with('applicantProfile:id,full_name')->orderBy('start_date')->get())
                        ->schema([
                            TextEntry::make('applicantProfile.full_name')->hiddenLabel()->weight('medium'),
                            TextEntry::make('role_title')->hiddenLabel(),
                            TextEntry::make('period')->hiddenLabel()->state(fn ($record) => $record->start_date->format('j M').' – '.($record->end_date?->format('j M Y') ?? '…')),
                            TextEntry::make('status')->hiddenLabel()->badge()
                                ->helperText(fn ($record) => $record->pcc_override ? __('Clearance override') : null),
                        ])->placeholder(__('No one assigned yet')),
                ]),
                Section::make(__('Conversation'))->description(__('Internal notes are never shown to the client.'))->schema([
                    ViewEntry::make('messages')->hiddenLabel()->view('filament.service-request-messages'),
                ]),
            ]),
            Grid::make(1)->columnSpan(1)->schema([
                Section::make(__('Requirements'))->schema([
                    TextEntry::make('required_headcount')->label(__('People required')),
                    TextEntry::make('required_experience_years')->label(__('Min. experience'))->suffix(' '.__('years')),
                    TextEntry::make('skills.name')->label(__('Skills'))->badge()->color('gray')->placeholder('—'),
                    TextEntry::make('requires_police_clearance')->label(__('Police clearance'))->formatStateUsing(fn (bool $state) => $state ? __('Required') : __('Not required'))->badge()->color(fn (bool $state) => $state ? 'warning' : 'gray'),
                    TextEntry::make('location')->label(__('Location'))->state(fn (ServiceRequest $r) => $r->location_text.', '.$r->district?->name_en),
                    TextEntry::make('schedule')->label(__('Start / duration'))
                        ->state(fn (ServiceRequest $r) => ($r->start_date?->format('j M Y') ?? __('Flexible')).($r->expected_duration_value ? ' · '.$r->expected_duration_value.' '.$r->expected_duration_unit : '')),
                    TextEntry::make('working_hours')->placeholder('—'),
                    TextEntry::make('budget_amount')->label(__('Client budget'))->money('LKR')->placeholder('—'),
                ]),
                Section::make(__('Management'))->schema([
                    TextEntry::make('manager.name')->label(__('Manager'))->placeholder(__('Unassigned')),
                    TextEntry::make('show_team_to_client')->label(__('Team visible to client'))->formatStateUsing(fn (bool $state) => $state ? __('Yes') : __('No')),
                ]),
                Section::make(__('Attachments'))->schema([
                    RepeatableEntry::make('attachments')->hiddenLabel()->schema([
                        TextEntry::make('title')->hiddenLabel()->icon(Heroicon::OutlinedPaperClip)->color('primary')
                            ->url(fn ($record) => route('files.show', [$record->file, 'download' => 1]))
                            ->helperText(fn ($record) => $record->visible_to_client ? null : __('Staff only')),
                    ])->placeholder(__('None')),
                ]),
                Section::make(__('History'))->collapsed()->schema([
                    RepeatableEntry::make('statusHistory')->hiddenLabel()->schema([
                        TextEntry::make('to_status')->hiddenLabel()->badge()
                            ->formatStateUsing(fn (string $state) => ServiceRequestStatus::tryFrom($state)?->getLabel() ?? $state)
                            ->helperText(fn ($record) => $record->created_at->format('j M Y H:i').($record->changer ? ' · '.$record->changer->name : '').($record->note ? ' — '.$record->note : '')),
                    ]),
                ]),
            ]),
        ]);
    }

    /** Staff who may be assigned as request manager. */
    public static function managerOptions(): array
    {
        return User::staff()->where('status', 'active')->get()
            ->filter(fn (User $u) => $u->can('service_requests.manage'))
            ->pluck('name', 'id')->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServiceRequests::route('/'),
            'view' => ViewServiceRequest::route('/{record}'),
            'matches' => Pages\MatchWorkforce::route('/{record}/matches'),
        ];
    }
}
