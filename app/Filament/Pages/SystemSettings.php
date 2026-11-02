<?php

namespace App\Filament\Pages;

use App\Support\Audit;
use App\Support\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Admin-configurable rules (police clearance renewal, uploads, matching,
 * staff session timeout). Values are stored in the `settings` table.
 *
 * @property-read Schema $form
 */
class SystemSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 50;

    protected static ?string $title = 'System settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('settings.manage') ?? false;
    }

    public function mount(): void
    {
        $weights = Settings::get('matching.weights');

        $this->form->fill([
            'pcc_validity' => Settings::get('pcc.default_validity_months'),
            'pcc_reminder_days' => array_map('strval', Settings::get('pcc.reminder_days')),
            'pcc_expiring_soon' => Settings::get('pcc.expiring_soon_days'),
            'upload_max_mb' => round(Settings::get('uploads.max_kb') / 1024, 1),
            'staff_idle' => Settings::get('session.staff_idle_minutes'),
            'w_skills' => $weights['skills'] ?? 40,
            'w_experience' => $weights['experience'] ?? 25,
            'w_qualifications' => $weights['qualifications'] ?? 15,
            'w_location' => $weights['location'] ?? 15,
            'w_completeness' => $weights['completeness'] ?? 5,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make(__('Police clearance renewal'))->columns(3)->schema([
                TextInput::make('pcc_validity')->label(__('Default validity (months from issue date)'))->numeric()->integer()->minValue(1)->maxValue(60)->required()
                    ->helperText(__('Suggested when approving; the verifier can set a different date per certificate.')),
                TextInput::make('pcc_expiring_soon')->label(__('Show “Expiring soon” within (days)'))->numeric()->integer()->minValue(1)->maxValue(180)->required(),
                TagsInput::make('pcc_reminder_days')->label(__('Reminder emails (days before due)'))->required()
                    ->placeholder(__('e.g. 30'))->helperText(__('Press Enter after each number.')),
            ]),
            Section::make(__('Uploads & sessions'))->columns(3)->schema([
                TextInput::make('upload_max_mb')->label(__('Maximum file size (MB)'))->numeric()->minValue(1)->maxValue(20)->required(),
                TextInput::make('staff_idle')->label(__('Staff idle sign-out (minutes)'))->numeric()->integer()->minValue(5)->maxValue(240)->required(),
            ]),
            Section::make(__('Workforce matching weights (%)'))->description(__('Must add up to 100.'))->columns(5)->schema([
                TextInput::make('w_skills')->label(__('Skills'))->numeric()->integer()->minValue(0)->maxValue(100)->required(),
                TextInput::make('w_experience')->label(__('Experience'))->numeric()->integer()->minValue(0)->maxValue(100)->required(),
                TextInput::make('w_qualifications')->label(__('Qualifications'))->numeric()->integer()->minValue(0)->maxValue(100)->required(),
                TextInput::make('w_location')->label(__('Location'))->numeric()->integer()->minValue(0)->maxValue(100)->required(),
                TextInput::make('w_completeness')->label(__('Profile completeness'))->numeric()->integer()->minValue(0)->maxValue(100)->required(),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label(__('Save settings'))->submit('save')])]),
        ]);
    }

    public function save(): void
    {
        $d = $this->form->getState();

        $days = collect($d['pcc_reminder_days'])->map(fn ($v) => (int) $v)->filter(fn ($v) => $v > 0 && $v <= 180)->unique()->sortDesc()->values()->all();
        if ($days === []) {
            throw ValidationException::withMessages(['data.pcc_reminder_days' => __('Enter at least one reminder day between 1 and 180.')]);
        }

        $weights = [
            'skills' => (int) $d['w_skills'], 'experience' => (int) $d['w_experience'], 'qualifications' => (int) $d['w_qualifications'],
            'location' => (int) $d['w_location'], 'completeness' => (int) $d['w_completeness'],
        ];
        if (array_sum($weights) !== 100) {
            throw ValidationException::withMessages(['data.w_skills' => __('Matching weights must add up to 100 (currently :n).', ['n' => array_sum($weights)])]);
        }

        $new = [
            'pcc.default_validity_months' => (int) $d['pcc_validity'],
            'pcc.reminder_days' => $days,
            'pcc.expiring_soon_days' => (int) $d['pcc_expiring_soon'],
            'uploads.max_kb' => (int) round((float) $d['upload_max_mb'] * 1024),
            'session.staff_idle_minutes' => (int) $d['staff_idle'],
            'matching.weights' => $weights,
        ];

        $old = collect(array_keys($new))->mapWithKeys(fn ($k) => [$k => Settings::get($k)])->all();
        foreach ($new as $key => $value) {
            Settings::set($key, $value, auth()->id());
        }

        Audit::log('settings.updated', null, $old, $new);
        Notification::make()->title(__('Settings saved'))->success()->send();
        $this->mount();
    }
}
