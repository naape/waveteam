<?php

namespace App\Filament\Resources\Applicants\RelationManagers;

use App\Actions\Admin\ApplicantRecordsActions;
use App\Support\Profile\ProfileSections;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

/**
 * Admin editing of one applicant profile section (education, certifications,
 * employment, …). Form fields, validation and the list summary all come from
 * ProfileSections, so the admin follows exactly the same rules as the applicant.
 */
abstract class ProfileSectionRelationManager extends RelationManager
{
    /** Key in ProfileSections::all(), e.g. 'education'. */
    protected static string $section;

    protected static function definition(): array
    {
        return ProfileSections::get(static::$section);
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return static::definition()['title'];
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->can('applicants.view');
    }

    /** Editable on the view page for staff with "update applicants". */
    public function isReadOnly(): bool
    {
        $user = auth()->user();

        return ! $user?->can('applicants.update')
            || ($this->hasSensitiveFields() && ! $user->can('applicants.view_pii'));
    }

    private function hasSensitiveFields(): bool
    {
        return collect(static::definition()['fields'])->contains(fn ($f) => $f['sensitive'] ?? false);
    }

    public function form(Schema $schema): Schema
    {
        $definition = static::definition();
        $fieldNames = array_keys($definition['fields']);
        $components = [];

        foreach ($definition['fields'] as $name => $field) {
            $components[] = $this->component($name, $field, $fieldNames);
        }

        return $schema->columns(2)->components($components);
    }

    private function component(string $name, array $field, array $siblings): Field
    {
        $component = match ($field['type']) {
            'number' => TextInput::make($name)->integer(),
            'date' => DatePicker::make($name),
            'select' => Select::make($name)->options($field['options'])->native(false),
            'checkbox' => Toggle::make($name)->inline(false),
            'checkboxes' => CheckboxList::make($name)->options($field['options'])->columns(7),
            'textarea' => Textarea::make($name)->rows(3),
            default => TextInput::make($name),
        };

        $component->label($field['label']);
        if (isset($field['hint'])) {
            $component->helperText($field['hint']);
        }
        if (($field['span'] ?? 1) === 2) {
            $component->columnSpanFull();
        }

        foreach ($field['rules'] as $rule) {
            if ($rule === 'required') {
                $component->required();
            } elseif (is_string($rule) && preg_match('/^(after_or_equal|before_or_equal|after|before):(.+)$/', $rule, $m)) {
                // Date comparisons with a sibling field or a literal like "today".
                $method = match ($m[1]) {
                    'after_or_equal' => 'afterOrEqual', 'before_or_equal' => 'beforeOrEqual', 'after' => 'after', 'before' => 'before',
                };
                $component->{$method}($m[2]);
            } elseif (! in_array($rule, ['nullable', 'array'], true)) {
                $component->rule($rule);
            }
        }

        if ($field['unique'] ?? false) {
            $component->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('applicant_profile_id', $this->getOwnerRecord()->getKey()));
        }

        return $component;
    }

    public function table(Table $table): Table
    {
        $definition = static::definition();
        $actions = app(ApplicantRecordsActions::class);
        $section = static::$section;

        $firstLabel = collect($definition['fields'])->first()['label'] ?? __('Details');

        return $table
            ->heading($definition['title'])
            ->columns([
                TextColumn::make('summary')->label($firstLabel)->wrap()->weight('medium')
                    ->state(fn (Model $record) => ($definition['summary'])($record)[0] ?? '')
                    ->description(fn (Model $record) => ($definition['summary'])($record)[1] ?? null),
            ])
            ->headerActions([
                CreateAction::make()->label($definition['add_label'])->modalHeading($definition['add_label'])->icon(Heroicon::OutlinedPlus)->outlined()->size('sm')
                    ->using(fn (array $data) => $actions->saveEntry($this->getOwnerRecord(), $section, null, $data, auth()->user())),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->tooltip(__('Edit'))
                    ->using(fn (Model $record, array $data) => $actions->saveEntry($this->getOwnerRecord(), $section, $record, $data, auth()->user())),
                Action::make('remove')->label(__('Remove'))->icon(Heroicon::OutlinedTrash)->color('danger')->iconButton()->tooltip(__('Remove'))
                    ->visible(fn () => ! $this->isReadOnly())
                    ->requiresConfirmation()
                    ->modalHeading(__('Remove this entry?'))
                    ->action(function (Model $record) use ($actions, $section) {
                        $actions->deleteEntry($this->getOwnerRecord(), $section, $record, auth()->user());
                        Notification::make()->title(__('Entry removed'))->success()->send();
                    }),
            ])
            ->emptyStateHeading(__('Nothing added yet'))
            ->emptyStateDescription(null)
            ->emptyStateIcon($definition['icon'] ?? Heroicon::OutlinedDocumentText)
            ->paginated(false);
    }
}
