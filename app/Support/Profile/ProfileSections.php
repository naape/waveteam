<?php

namespace App\Support\Profile;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Declarative definitions of the applicant's repeatable profile sections.
 * One generic controller + view render and validate all of them, so every
 * section gets the same ownership checks, validation and audit behaviour.
 */
class ProfileSections
{
    public const VEHICLE_CLASSES = ['A1', 'A', 'B1', 'B', 'C1', 'C', 'CE', 'D1', 'D', 'DE', 'G1', 'G', 'J'];

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        $year = (int) date('Y');
        $levels = ['none' => __('None'), 'basic' => __('Basic'), 'good' => __('Good'), 'fluent' => __('Fluent')];

        return [
            'education' => [
                'title' => __('Education'),
                'intro' => __('Add your school, vocational and higher education qualifications, most recent first.'),
                'relation' => 'educations',
                'add_label' => __('Add qualification'),
                'fields' => [
                    'qualification' => ['type' => 'text', 'label' => __('Qualification'), 'hint' => __('e.g. G.C.E. A/L, NVQ Level 4 Electrician, B.Sc. Engineering'), 'rules' => ['required', 'string', 'max:200']],
                    'institution' => ['type' => 'text', 'label' => __('Institution'), 'rules' => ['required', 'string', 'max:200']],
                    'year_completed' => ['type' => 'number', 'label' => __('Year completed'), 'rules' => ['nullable', 'integer', 'min:1950', 'max:'.($year + 6)]],
                    'result_grade' => ['type' => 'text', 'label' => __('Results / grade'), 'rules' => ['nullable', 'string', 'max:100']],
                ],
                'summary' => fn (Model $m) => [$m->qualification, trim($m->institution.($m->year_completed ? ' · '.$m->year_completed : '').($m->result_grade ? ' · '.$m->result_grade : ''))],
            ],

            'certifications' => [
                'title' => __('Certifications & training'),
                'intro' => __('Professional certifications, technical qualifications and specialised training (e.g. tower climbing, working at height, first aid).'),
                'relation' => 'certifications',
                'add_label' => __('Add certification'),
                'fields' => [
                    'kind' => ['type' => 'select', 'label' => __('Type'), 'options' => ['professional' => __('Professional certification'), 'technical' => __('Technical qualification'), 'training' => __('Specialised training')], 'rules' => ['required', Rule::in(['professional', 'technical', 'training'])]],
                    'title' => ['type' => 'text', 'label' => __('Title'), 'rules' => ['required', 'string', 'max:200']],
                    'issuing_body' => ['type' => 'text', 'label' => __('Issued by'), 'rules' => ['nullable', 'string', 'max:200']],
                    'issued_on' => ['type' => 'date', 'label' => __('Issue date'), 'rules' => ['nullable', 'date', 'before_or_equal:today']],
                    'expires_on' => ['type' => 'date', 'label' => __('Expiry date (if any)'), 'rules' => ['nullable', 'date', 'after_or_equal:issued_on']],
                ],
                'summary' => fn (Model $m) => [$m->title, trim(ucfirst($m->kind).($m->issuing_body ? ' · '.$m->issuing_body : '').($m->expires_on ? ' · '.__('expires :d', ['d' => $m->expires_on->format('M Y')]) : ''))],
            ],

            'employment' => [
                'title' => __('Employment history'),
                'intro' => __('Your previous and current employers, most recent first.'),
                'relation' => 'employments',
                'add_label' => __('Add employer'),
                'fields' => [
                    'employer' => ['type' => 'text', 'label' => __('Employer'), 'rules' => ['required', 'string', 'max:200']],
                    'designation' => ['type' => 'text', 'label' => __('Job title / designation'), 'rules' => ['required', 'string', 'max:150']],
                    'start_date' => ['type' => 'date', 'label' => __('From'), 'rules' => ['required', 'date', 'before_or_equal:today']],
                    'end_date' => ['type' => 'date', 'label' => __('To'), 'hint' => __('Leave empty if you still work here'), 'rules' => ['nullable', 'date', 'after_or_equal:start_date', 'before_or_equal:today']],
                    'is_current' => ['type' => 'checkbox', 'label' => __('I currently work here'), 'rules' => ['boolean']],
                    'responsibilities' => ['type' => 'textarea', 'label' => __('Main responsibilities'), 'rules' => ['nullable', 'string', 'max:2000'], 'span' => 2],
                ],
                'summary' => fn (Model $m) => [$m->designation.' — '.$m->employer, ($m->start_date?->format('M Y') ?? '?').' – '.($m->is_current ? __('Present') : ($m->end_date?->format('M Y') ?? '?'))],
            ],

            'languages' => [
                'title' => __('Languages'),
                'intro' => __('Languages you can work in.'),
                'relation' => 'languages',
                'add_label' => __('Add language'),
                'fields' => [
                    'language' => ['type' => 'select', 'label' => __('Language'), 'options' => ['Sinhala' => 'Sinhala', 'Tamil' => 'Tamil', 'English' => 'English', 'Hindi' => 'Hindi', 'Arabic' => 'Arabic', 'Other' => __('Other')], 'rules' => ['required', 'string', 'max:60'], 'unique' => true],
                    'speak' => ['type' => 'select', 'label' => __('Speaking'), 'options' => $levels, 'rules' => ['required', Rule::in(array_keys($levels))]],
                    'read' => ['type' => 'select', 'label' => __('Reading'), 'options' => $levels, 'rules' => ['required', Rule::in(array_keys($levels))]],
                    'write' => ['type' => 'select', 'label' => __('Writing'), 'options' => $levels, 'rules' => ['required', Rule::in(array_keys($levels))]],
                ],
                'summary' => fn (Model $m) => [$m->language, __('Speak: :s · Read: :r · Write: :w', ['s' => $m->speak, 'r' => $m->read, 'w' => $m->write])],
            ],

            'driving-licences' => [
                'title' => __('Driving licence'),
                'intro' => __('Your Sri Lankan driving licence details. Upload a copy under Documents.'),
                'relation' => 'drivingLicences',
                'add_label' => __('Add licence'),
                'fields' => [
                    'licence_number' => ['type' => 'text', 'label' => __('Licence number'), 'rules' => ['required', 'string', 'max:30'], 'sensitive' => true],
                    'expires_on' => ['type' => 'date', 'label' => __('Expiry date'), 'rules' => ['nullable', 'date']],
                    'vehicle_classes' => ['type' => 'checkboxes', 'label' => __('Vehicle classes'), 'options' => array_combine(self::VEHICLE_CLASSES, self::VEHICLE_CLASSES), 'rules' => ['nullable', 'array'], 'item_rules' => [Rule::in(self::VEHICLE_CLASSES)], 'span' => 2],
                ],
                'summary' => fn (Model $m) => [__('Licence ending :x', ['x' => substr((string) $m->licence_number, -4)]), implode(', ', $m->vehicle_classes ?? []).($m->expires_on ? ' · '.__('expires :d', ['d' => $m->expires_on->format('M Y')]) : '')],
            ],

            'military' => [
                'title' => __('Military service (optional)'),
                'intro' => __('Relevant service with the armed forces, if any.'),
                'relation' => 'militaryServices',
                'add_label' => __('Add service record'),
                'optional' => true,
                'fields' => [
                    'branch' => ['type' => 'select', 'label' => __('Branch'), 'options' => ['Sri Lanka Army' => 'Sri Lanka Army', 'Sri Lanka Navy' => 'Sri Lanka Navy', 'Sri Lanka Air Force' => 'Sri Lanka Air Force', 'Civil Defence Force' => 'Civil Defence Force', 'Other' => __('Other')], 'rules' => ['required', 'string', 'max:100']],
                    'rank' => ['type' => 'text', 'label' => __('Rank'), 'rules' => ['nullable', 'string', 'max:100']],
                    'service_from' => ['type' => 'date', 'label' => __('From'), 'rules' => ['nullable', 'date', 'before_or_equal:today']],
                    'service_to' => ['type' => 'date', 'label' => __('To'), 'rules' => ['nullable', 'date', 'after_or_equal:service_from']],
                    'notes' => ['type' => 'textarea', 'label' => __('Trade / specialisation'), 'rules' => ['nullable', 'string', 'max:1000'], 'span' => 2],
                ],
                'summary' => fn (Model $m) => [$m->branch.($m->rank ? ' · '.$m->rank : ''), trim(($m->service_from?->format('Y') ?? '').' – '.($m->service_to?->format('Y') ?? ''), ' –')],
            ],
        ];
    }

    public static function get(string $key): array
    {
        return self::all()[$key] ?? abort(404);
    }

    /** Validation rules for a section, built from its field definitions. */
    public static function rules(array $section, string $table, int $profileId, ?int $ignoreId = null): array
    {
        $rules = [];
        foreach ($section['fields'] as $name => $field) {
            $rules[$name] = $field['rules'];

            if ($field['unique'] ?? false) {
                $rules[$name][] = Rule::unique($table, $name)->where('applicant_profile_id', $profileId)->ignore($ignoreId);
            }

            if (isset($field['item_rules'])) {
                $rules[$name.'.*'] = $field['item_rules'];
            }
        }

        return $rules;
    }

    /** Normalises validated section data before saving (shared by the applicant and admin forms). */
    public static function normalize(array $section, array $data): array
    {
        foreach ($section['fields'] as $name => $field) {
            if ($field['type'] === 'checkbox') {
                $data[$name] = (bool) ($data[$name] ?? false);
            }
            if ($field['type'] === 'checkboxes') {
                $data[$name] = array_values($data[$name] ?? []);
            }
        }

        if (array_key_exists('is_current', $data) && $data['is_current']) {
            $data['end_date'] = null;
        }

        return array_intersect_key($data, $section['fields']);
    }

    /** Wizard navigation (all profile pages, in order). */
    public static function steps(): array
    {
        return [
            ['route' => 'applicant.profile.personal', 'params' => [], 'label' => __('Personal details'), 'key' => 'personal'],
            ['route' => 'applicant.profile.section', 'params' => ['section' => 'education'], 'label' => __('Education'), 'key' => 'education'],
            ['route' => 'applicant.profile.section', 'params' => ['section' => 'certifications'], 'label' => __('Certifications'), 'key' => 'certifications'],
            ['route' => 'applicant.profile.skills', 'params' => [], 'label' => __('Skills & experience'), 'key' => 'skills'],
            ['route' => 'applicant.profile.section', 'params' => ['section' => 'employment'], 'label' => __('Employment'), 'key' => 'employment'],
            ['route' => 'applicant.profile.section', 'params' => ['section' => 'languages'], 'label' => __('Languages'), 'key' => 'languages'],
            ['route' => 'applicant.profile.section', 'params' => ['section' => 'driving-licences'], 'label' => __('Driving licence'), 'key' => 'driving-licences'],
            ['route' => 'applicant.profile.section', 'params' => ['section' => 'military'], 'label' => __('Military service'), 'key' => 'military'],
            ['route' => 'applicant.profile.preferences', 'params' => [], 'label' => __('Preferences'), 'key' => 'preferences'],
        ];
    }

    public static function nextStep(string $key): ?array
    {
        $steps = self::steps();
        $i = collect($steps)->search(fn ($s) => $s['key'] === $key);

        return $i !== false ? ($steps[$i + 1] ?? null) : null;
    }
}
