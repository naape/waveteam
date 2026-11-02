<?php

namespace App\Filament\Resources\NotificationTemplates;

use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Resources\NotificationTemplates\Pages\EditNotificationTemplate;
use App\Filament\Resources\NotificationTemplates\Pages\ListNotificationTemplates;
use App\Models\NotificationTemplate;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Admin-editable email wording. Placeholders like {{name}} are replaced when
 * sending. Mandatory templates (security, compliance) cannot be switched off.
 */
class NotificationTemplateResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = NotificationTemplate::class;

    protected static ?string $slug = 'email-templates';

    protected static ?string $modelLabel = 'email template';

    protected static ?string $navigationLabel = 'Email templates';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 40;

    protected static array $permissions = ['view' => 'notifications.manage', 'edit' => 'notifications.manage'];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->schema([
                Text::make(fn (?NotificationTemplate $record) => __('Key: :k · Channel: :c · Language: :l', ['k' => $record?->key, 'c' => $record?->channel, 'l' => $record?->locale]))->color('gray'),
                TextInput::make('name')->required()->maxLength(150),
                TextInput::make('subject')->required()->maxLength(255),
                Textarea::make('body')->required()->rows(8)->maxLength(5000)
                    ->helperText(__('Markdown. Separate paragraphs with a blank line. Use **bold** for emphasis. Placeholders: {{name}} plus those already in the text (e.g. {{reference}}, {{vacancy_title}}). Unknown placeholders are left blank.')),
                Toggle::make('is_enabled')->label(__('Enabled'))
                    ->disabled(fn (?NotificationTemplate $record) => (bool) $record?->is_mandatory)
                    ->helperText(fn (?NotificationTemplate $record) => $record?->is_mandatory ? __('Mandatory: security and compliance emails are always sent.') : null),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->description(fn (NotificationTemplate $r) => $r->key),
                TextColumn::make('subject')->limit(50)->searchable(),
                TextColumn::make('channel')->badge()->color('gray'),
                IconColumn::make('is_mandatory')->label(__('Mandatory'))->boolean(),
                IconColumn::make('is_enabled')->label(__('Enabled'))->boolean(),
                TextColumn::make('updated_at')->since()->label(__('Last edited')),
            ])
            ->defaultSort('key')
            ->filters([TernaryFilter::make('is_enabled')])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotificationTemplates::route('/'),
            'edit' => EditNotificationTemplate::route('/{record}/edit'),
        ];
    }
}
