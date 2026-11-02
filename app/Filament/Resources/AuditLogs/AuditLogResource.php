<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Read-only audit trail viewer. Entries can never be created, edited or
 * deleted from the UI (the model itself also refuses updates/deletes).
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 90;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('audit_logs.view') ?? false;
    }

    public static function canView($record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('actor');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label(__('When'))->dateTime('d M Y H:i:s')->sortable(),
                TextColumn::make('actor.name')->label(__('Actor'))->placeholder(__('System / guest'))
                    ->description(fn (AuditLog $r) => $r->actor_type)->searchable(),
                TextColumn::make('action')->badge()
                    ->color(fn (string $state) => match (true) {
                        str_contains($state, 'failed'), str_contains($state, 'lockout'), str_contains($state, 'deactivated') => 'danger',
                        str_starts_with($state, 'auth.') => 'gray',
                        default => 'primary',
                    })->searchable(),
                TextColumn::make('subject')->label(__('Subject'))
                    ->state(fn (AuditLog $r) => $r->auditable_type ? class_basename($r->auditable_type).' #'.$r->auditable_id : null)
                    ->placeholder('—'),
                TextColumn::make('ip')->label(__('IP'))->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('category')
                    ->label(__('Category'))
                    ->options([
                        'auth.' => __('Authentication'),
                        'account.' => __('Account security'),
                        'user.' => __('Account status'),
                        'staff.' => __('Staff management'),
                        'organization.' => __('Organisations'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn ($q, $v) => $q->where('action', 'like', $v.'%'))),
                SelectFilter::make('actor_type')->options([
                    'staff' => 'Staff', 'applicant' => 'Applicant', 'org_contact' => 'Organisation', 'system' => 'System / guest',
                ]),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label(__('From')),
                        DatePicker::make('until')->label(__('Until')),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))),
            ])
            ->recordActions([ViewAction::make()])
            ->paginated([25, 50, 100]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('created_at')->label(__('When'))->dateTime('d M Y H:i:s'),
                TextEntry::make('action')->badge(),
                TextEntry::make('actor.name')->label(__('Actor'))->placeholder(__('System / guest')),
                TextEntry::make('auditable_type')->label(__('Subject type'))->formatStateUsing(fn (?string $state) => $state ? class_basename($state) : null)->placeholder('—'),
                TextEntry::make('auditable_id')->label(__('Subject ID'))->placeholder('—'),
                TextEntry::make('ip')->label(__('IP address'))->placeholder('—'),
                TextEntry::make('user_agent')->label(__('User agent'))->columnSpanFull()->placeholder('—'),
            ]),
            Section::make(__('Changes'))->columns(2)->schema([
                KeyValueEntry::make('old_values')->label(__('Before'))->placeholder('—'),
                KeyValueEntry::make('new_values')->label(__('After'))->placeholder('—'),
                KeyValueEntry::make('meta')->label(__('Details'))->columnSpanFull()->placeholder('—'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
            'view' => ViewAuditLog::route('/{record}'),
        ];
    }
}
