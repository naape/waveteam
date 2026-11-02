<?php

namespace App\Filament\Resources\Organizations;

use App\Actions\Admin\OrganizationActions;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Resources\Organizations\Pages\EditOrganization;
use App\Filament\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Resources\Organizations\Pages\ViewOrganization;
use App\Filament\Resources\ServiceRequests\ServiceRequestResource;
use App\Models\Organization;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
use UnitEnum;

class OrganizationResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = Organization::class;

    protected static ?string $modelLabel = 'organisation';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Clients & Services';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    protected static array $permissions = ['view' => 'organizations.view', 'edit' => 'organizations.manage'];

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('serviceRequests');
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }
        $n = Organization::where('status', OrganizationStatus::Pending)->count();

        return $n ? (string) $n : null;
    }

    public static function statusActions(): array
    {
        $run = function (Organization $r, OrganizationStatus $status, ?string $note, string $done) {
            app(OrganizationActions::class)->setStatus($r, $status, auth()->user(), $note);
            Notification::make()->title($done)->success()->send();
        };
        $canVerify = fn () => auth()->user()->can('organizations.verify');

        return [
            Action::make('verify')->label(__('Verify'))->icon(Heroicon::OutlinedCheckBadge)->color('success')
                ->visible(fn (Organization $r) => $canVerify() && $r->status === OrganizationStatus::Pending)
                ->requiresConfirmation()
                ->modalDescription(__('Confirm you have checked this organisation’s identity (e.g. business registration).'))
                ->action(fn (Organization $r) => $run($r, OrganizationStatus::Active, null, __('Organisation verified'))),
            Action::make('suspend')->label(__('Suspend'))->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                ->visible(fn (Organization $r) => $canVerify() && $r->status !== OrganizationStatus::Suspended)
                ->schema([Textarea::make('note')->label(__('Reason (sent to the organisation)'))->required()->maxLength(1000)])
                ->action(fn (Organization $r, array $data) => $run($r, OrganizationStatus::Suspended, $data['note'], __('Organisation suspended'))),
            Action::make('reactivate')->label(__('Reactivate'))->icon(Heroicon::OutlinedArrowPath)->color('success')
                ->visible(fn (Organization $r) => $canVerify() && $r->status === OrganizationStatus::Suspended)
                ->requiresConfirmation()
                ->action(fn (Organization $r) => $run($r, OrganizationStatus::Active, null, __('Organisation reactivated'))),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->columnSpanFull()->schema([
                TextInput::make('name')->required()->maxLength(200)->columnSpan(2),
                Select::make('organization_type')->options(OrganizationType::options())->required(),
                TextInput::make('registration_number')->maxLength(60),
                TextInput::make('email')->email()->required()->maxLength(190),
                TextInput::make('phone')->tel()->required()->maxLength(20),
                Textarea::make('address')->required()->rows(2)->maxLength(500),
                Textarea::make('billing_address')->rows(2)->maxLength(500),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->description(fn (Organization $r) => $r->organization_type->getLabel()),
                TextColumn::make('registration_number')->label(__('Reg. no.'))->searchable()->placeholder('—')->toggleable(),
                TextColumn::make('email')->searchable()->toggleable(),
                TextColumn::make('service_requests_count')->label(__('Requests'))->numeric()->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('created_at')->label(__('Registered'))->date()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(OrganizationStatus::options()),
                SelectFilter::make('organization_type')->label(__('Type'))->options(OrganizationType::options()),
            ])
            ->recordActions([ViewAction::make(), ...static::statusActions()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->columnSpanFull()->schema([
                TextEntry::make('name')->columnSpan(2)->weight('bold'),
                TextEntry::make('status')->badge(),
                TextEntry::make('organization_type')->badge()->color('gray'),
                TextEntry::make('registration_number')->placeholder('—'),
                TextEntry::make('verified_at')->dateTime()->placeholder(__('Not verified')),
                TextEntry::make('email')->copyable(),
                TextEntry::make('phone'),
                TextEntry::make('created_at')->label(__('Registered'))->dateTime(),
                TextEntry::make('address'),
                TextEntry::make('billing_address')->placeholder('—'),
            ]),
            Section::make(__('Contacts'))->columnSpanFull()->schema([
                RepeatableEntry::make('members')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('user.name')->hiddenLabel()->weight('medium'),
                    TextEntry::make('designation')->hiddenLabel()->placeholder('—'),
                    TextEntry::make('user.email')->hiddenLabel()->copyable(),
                    TextEntry::make('user.status')->hiddenLabel()->badge(),
                ]),
            ]),
            Section::make(__('Supporting documents'))->columnSpanFull()->schema([
                RepeatableEntry::make('documents')->hiddenLabel()->columns(3)->schema([
                    TextEntry::make('title')->hiddenLabel(),
                    TextEntry::make('created_at')->hiddenLabel()->date(),
                    TextEntry::make('open')->hiddenLabel()->state(__('Open'))->color('primary')->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                        ->url(fn ($record) => route('files.show', $record->file), shouldOpenInNewTab: true),
                ])->placeholder(__('None uploaded')),
            ]),
            Section::make(__('Service requests'))->columnSpanFull()->schema([
                RepeatableEntry::make('serviceRequests')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('reference')->hiddenLabel()->fontFamily('mono')->color('primary')
                        ->url(fn ($record) => ServiceRequestResource::getUrl('view', ['record' => $record])),
                    TextEntry::make('title')->hiddenLabel()->columnSpan(2),
                    TextEntry::make('status')->hiddenLabel()->badge(),
                ])->placeholder(__('No requests yet')),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrganizations::route('/'),
            'view' => ViewOrganization::route('/{record}'),
            'edit' => EditOrganization::route('/{record}/edit'),
        ];
    }
}
