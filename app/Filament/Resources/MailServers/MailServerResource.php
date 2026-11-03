<?php

namespace App\Filament\Resources\MailServers;

use App\Actions\Admin\MailServerActions;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Resources\MailServers\Pages\CreateMailServer;
use App\Filament\Resources\MailServers\Pages\EditMailServer;
use App\Filament\Resources\MailServers\Pages\ListMailServers;
use App\Models\MailServer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Outgoing email (SMTP) servers. One may be active; it overrides the MAIL_*
 * settings in .env for every email the portal sends.
 */
class MailServerResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = MailServer::class;

    protected static ?string $slug = 'email-servers';

    protected static ?string $modelLabel = 'email server';

    protected static ?string $navigationLabel = 'Email servers (SMTP)';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 45;

    protected static array $permissions = [
        'view' => 'settings.manage', 'create' => 'settings.manage', 'edit' => 'settings.manage', 'delete' => 'settings.manage',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Server'))->columns(2)->columnSpanFull()->schema([
                TextInput::make('description')->required()->maxLength(150)->columnSpanFull()
                    ->placeholder(__('e.g. Brevo transactional, HostGator mail')),
                TextInput::make('host')->label(__('SMTP server (name or IP address)'))->required()->maxLength(255)
                    ->placeholder('smtp.example.com')
                    ->rule('regex:/^([A-Za-z0-9-]+\.)*[A-Za-z0-9-]+$|^\d{1,3}(\.\d{1,3}){3}$|^\[?[0-9A-Fa-f:]+\]?$/'),
                TextInput::make('port')->required()->integer()->minValue(1)->maxValue(65535)->default(587),
                Select::make('encryption')->options(MailServer::ENCRYPTIONS)->required()->default('tls')->selectablePlaceholder(false)
                    ->live()
                    ->afterStateUpdated(fn (?string $state, Set $set) => match ($state) {
                        'ssl' => $set('port', 465), 'tls' => $set('port', 587), default => null,
                    }),
                TextInput::make('timeout')->label(__('Timeout (seconds)'))->integer()->minValue(5)->maxValue(120)->default(30)->required(),
            ]),
            Section::make(__('Sign-in'))->columns(2)->columnSpanFull()->schema([
                TextInput::make('username')->label(__('User name'))->maxLength(255)->autocomplete('off'),
                TextInput::make('password')->password()->revealable()->maxLength(500)->autocomplete('new-password')
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->helperText(fn (?MailServer $record) => $record?->password
                        ? __('A password is saved. Leave blank to keep it.')
                        : __('Stored encrypted. It is never shown again after saving.')),
            ]),
            Section::make(__('Sender'))->columns(2)->columnSpanFull()->schema([
                TextInput::make('from_address')->label(__('From email address'))->email()->required()->maxLength(190)
                    ->default(config('mail.from.address'))
                    ->helperText(__('Must be an address the server is allowed to send from (check SPF/DKIM).')),
                TextInput::make('from_name')->label(__('From name'))->required()->maxLength(150)->default(config('mail.from.name')),
                TextInput::make('reply_to_address')->label(__('Reply-to address (optional)'))->email()->maxLength(190),
            ]),
            Section::make(__('Advanced'))->columns(2)->columnSpanFull()->collapsed()->schema([
                TextInput::make('local_domain')->label(__('HELO/EHLO name (optional)'))->maxLength(255)
                    ->helperText(__('Only if your provider asks for it, e.g. www.waveteam.lk')),
                Toggle::make('verify_peer')->label(__('Verify the server’s TLS certificate'))->default(true)
                    ->helperText(__('Turn off only for an internal server with a self-signed certificate.')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('description')->weight('medium')->searchable()
                    ->description(fn (MailServer $record) => "{$record->host}:{$record->port}"),
                TextColumn::make('encryption')->badge()->color('gray')
                    ->formatStateUsing(fn (string $state) => strtoupper($state === 'tls' ? 'starttls' : $state)),
                TextColumn::make('username')->label(__('User name'))->placeholder('—')->toggleable(),
                TextColumn::make('from_address')->label(__('From'))->description(fn (MailServer $record) => $record->from_name),
                IconColumn::make('is_active')->label(__('In use'))->boolean(),
                TextColumn::make('last_tested_at')->label(__('Last test'))->since()->placeholder(__('Never'))
                    ->description(fn (MailServer $record) => $record->last_tested_at ? ($record->last_test_ok ? __('Succeeded') : __('Failed')) : null)
                    ->color(fn (MailServer $record) => $record->last_tested_at ? ($record->last_test_ok ? 'success' : 'danger') : null)
                    ->tooltip(fn (MailServer $record) => $record->last_test_message),
            ])
            ->defaultSort('is_active', 'desc')
            ->recordActions([
                static::testAction(),
                Action::make('activate')->label(__('Use this server'))->icon(Heroicon::OutlinedCheckCircle)->color('success')
                    ->visible(fn (MailServer $record) => ! $record->is_active)
                    ->requiresConfirmation()
                    ->modalDescription(fn (MailServer $record) => $record->last_test_ok
                        ? __('All portal emails will be sent through this server from now on.')
                        : __('This server has not passed a test yet. Send a test email first, or emails may stop being delivered.'))
                    ->action(function (MailServer $record) {
                        app(MailServerActions::class)->activate($record, auth()->user());
                        Notification::make()->title(__('Now sending email through :d', ['d' => $record->description]))->success()->send();
                    }),
                Action::make('deactivate')->label(__('Stop using'))->icon(Heroicon::OutlinedPauseCircle)->color('gray')
                    ->visible(fn (MailServer $record) => $record->is_active)
                    ->requiresConfirmation()
                    ->modalDescription(__('Emails will fall back to the server set in the .env file.'))
                    ->action(function (MailServer $record) {
                        app(MailServerActions::class)->deactivate($record, auth()->user());
                        Notification::make()->title(__('Using the .env email settings'))->success()->send();
                    }),
                EditAction::make(),
                Action::make('delete')->label(__('Delete'))->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->visible(fn (MailServer $record) => ! $record->is_active)
                    ->requiresConfirmation()
                    ->action(function (MailServer $record) {
                        app(MailServerActions::class)->delete($record, auth()->user());
                        Notification::make()->title(__('Email server deleted'))->success()->send();
                    }),
            ])
            ->emptyStateHeading(__('No email servers yet'))
            ->emptyStateDescription(__('Until you add and activate one, emails use the MAIL_* settings in the .env file.'));
    }

    public static function testAction(): Action
    {
        return Action::make('test')->label(__('Send test email'))->icon(Heroicon::OutlinedPaperAirplane)->color('gray')
            ->modalDescription(__('Sends a short message through this server using its saved settings. Save any changes first.'))
            ->fillForm(fn () => ['to' => auth()->user()->email])
            ->schema([TextInput::make('to')->label(__('Send to'))->email()->required()])
            ->modalSubmitActionLabel(__('Send'))
            ->action(function (MailServer $record, array $data) {
                [$ok, $message] = app(MailServerActions::class)->sendTest($record, $data['to'], auth()->user());
                $ok
                    ? Notification::make()->title(__('Test email sent'))->body($message)->success()->send()
                    : Notification::make()->title(__('Test failed'))->body($message)->danger()->persistent()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMailServers::route('/'),
            'create' => CreateMailServer::route('/create'),
            'edit' => EditMailServer::route('/{record}/edit'),
        ];
    }
}
