<?php

namespace App\Http\Controllers\Applicant;

use App\Actions\Applicant\UploadApplicantDocument;
use App\Enums\AvailabilityStatus;
use App\Enums\DocumentType;
use App\Http\Controllers\Controller;
use App\Models\ApplicantProfile;
use App\Models\District;
use App\Models\JobCategory;
use App\Rules\PhoneNumberRule;
use App\Support\Audit;
use App\Support\Nic;
use App\Support\PhoneNumber;
use App\Support\Profile\ProfileCompletion;
use App\Support\Profile\ProfileSections;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The applicant's own profile. Every query is scoped through the signed-in
 * user's profile, so one applicant can never read or change another's data.
 */
class ProfileController extends Controller
{
    private function profile(Request $request): ApplicantProfile
    {
        return $request->user()->applicantProfile()->firstOrFail();
    }

    private function done(ApplicantProfile $profile, string $stepKey, string $message): RedirectResponse
    {
        ProfileCompletion::refresh($profile);
        $next = ProfileSections::nextStep($stepKey);

        return ($next && request()->boolean('continue')
            ? redirect()->route($next['route'], $next['params'])
            : back())->with('status', $message);
    }

    // ---- Personal details -------------------------------------------------------

    public function editPersonal(Request $request): View
    {
        return view('applicant.profile.personal', [
            'profile' => $this->profile($request),
            'districts' => District::orderBy('name_en')->get()->mapWithKeys(fn ($d) => [$d->id => $d->localizedName()]),
        ]);
    }

    public function updatePersonal(Request $request, UploadApplicantDocument $upload): RedirectResponse
    {
        $profile = $this->profile($request);
        $nicYear = Nic::birthYear($profile->nic_number);

        $data = $request->validate([
            'full_name' => ['required', 'string', 'min:3', 'max:150'],
            'passport_number' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9]+$/'],
            'date_of_birth' => ['required', 'date', 'before_or_equal:'.now()->subYears(16)->toDateString(), 'after:1940-01-01',
                function ($attr, $value, $fail) use ($nicYear) {
                    if ($nicYear && (int) date('Y', strtotime($value)) !== $nicYear) {
                        $fail(__('Date of birth does not match the birth year in your NIC (:year).', ['year' => $nicYear]));
                    }
                }],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other', 'prefer_not_to_say'])],
            'permanent_address' => ['required', 'string', 'max:500'],
            'current_address' => ['nullable', 'required_unless:current_same,1', 'string', 'max:500'],
            'district_id' => ['required', 'exists:districts,id'],
            'contact_number' => ['required', 'string', 'max:25', new PhoneNumberRule],
            'whatsapp_number' => ['nullable', 'string', 'max:25', new PhoneNumberRule],
            'emergency_contact_name' => ['required', 'string', 'max:150'],
            'emergency_contact_number' => ['required', 'string', 'max:25', new PhoneNumberRule],
            'photo' => ['nullable', 'file', 'max:'.(int) \App\Support\Settings::get('uploads.max_kb')],
        ], [], [
            'district_id' => __('district'),
            'emergency_contact_name' => __('emergency contact person'),
        ]);

        $old = Arr::only($profile->getAttributes(), ['full_name', 'district_id', 'date_of_birth']);

        $profile->fill([
            ...Arr::except($data, ['photo']),
            'passport_number' => filled($data['passport_number'] ?? null) ? strtoupper($data['passport_number']) : null,
            'current_address' => $request->boolean('current_same') ? $data['permanent_address'] : $data['current_address'],
            'contact_number' => PhoneNumber::normalize($data['contact_number']),
            'whatsapp_number' => $request->boolean('whatsapp_same') ? PhoneNumber::normalize($data['contact_number']) : PhoneNumber::normalize($data['whatsapp_number'] ?? null),
            'emergency_contact_number' => PhoneNumber::normalize($data['emergency_contact_number']),
        ])->save();

        $request->user()->forceFill(['name' => $data['full_name']])->save();

        if ($request->hasFile('photo')) {
            $upload($profile, DocumentType::Photo, $request->file('photo'), $request->user(), __('Profile photo'), 'photo');
        }

        Audit::log('applicant.profile_updated', $profile, $old, Arr::only($profile->getAttributes(), ['full_name', 'district_id', 'date_of_birth']), ['section' => 'personal']);

        return $this->done($profile, 'personal', __('Personal details saved.'));
    }

    // ---- Repeatable sections (education, certifications, employment, …) --------

    public function section(Request $request, string $section): View
    {
        $definition = ProfileSections::get($section);
        $profile = $this->profile($request);
        $entries = $profile->{$definition['relation']}()->get();
        $editing = $request->filled('edit') ? $entries->firstWhere('id', (int) $request->query('edit')) : null;
        abort_if($request->filled('edit') && $editing === null, 404);

        return view('applicant.profile.section', compact('definition', 'section', 'profile', 'entries', 'editing'));
    }

    public function storeEntry(Request $request, string $section): RedirectResponse
    {
        $definition = ProfileSections::get($section);
        $profile = $this->profile($request);
        $relation = $profile->{$definition['relation']}();

        $data = $request->validate(ProfileSections::rules($definition, $relation->getRelated()->getTable(), $profile->id));
        $entry = $relation->create($this->normalize($definition, $data));

        Audit::log('applicant.profile_entry_added', $entry, meta: ['section' => $section]);

        return $this->done($profile, $section, __(':section updated.', ['section' => $definition['title']]))
            ->withFragment('entries');
    }

    public function updateEntry(Request $request, string $section, int $entry): RedirectResponse
    {
        $definition = ProfileSections::get($section);
        $profile = $this->profile($request);
        $relation = $profile->{$definition['relation']}();
        $model = $relation->findOrFail($entry); // scoped: 404 for anyone else's entry

        $data = $request->validate(ProfileSections::rules($definition, $relation->getRelated()->getTable(), $profile->id, $model->id));
        $model->update($this->normalize($definition, $data));

        Audit::log('applicant.profile_entry_updated', $model, meta: ['section' => $section]);

        return redirect()->route('applicant.profile.section', $section)->with('status', __('Entry updated.'));
    }

    public function destroyEntry(Request $request, string $section, int $entry): RedirectResponse
    {
        $definition = ProfileSections::get($section);
        $profile = $this->profile($request);
        $model = $profile->{$definition['relation']}()->findOrFail($entry);

        $model->delete();
        Audit::log('applicant.profile_entry_removed', $model, meta: ['section' => $section]);
        ProfileCompletion::refresh($profile);

        return redirect()->route('applicant.profile.section', $section)->with('status', __('Entry removed.'));
    }

    private function normalize(array $definition, array $data): array
    {
        foreach ($definition['fields'] as $name => $field) {
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

        return $data;
    }

    // ---- Skills & categories ----------------------------------------------------

    public function editSkills(Request $request): View
    {
        $profile = $this->profile($request)->load(['skills', 'jobCategories']);

        return view('applicant.profile.skills', [
            'profile' => $profile,
            'categories' => JobCategory::where('is_active', true)->orderBy('sort')->with(['skills' => fn ($q) => $q->where('is_active', true)->orderBy('name')])->get(),
            'selectedSkills' => $profile->skills->keyBy('id'),
            'selectedCategories' => $profile->jobCategories->pluck('id')->all(),
        ]);
    }

    public function updateSkills(Request $request): RedirectResponse
    {
        $profile = $this->profile($request);

        $data = $request->validate([
            'job_categories' => ['required', 'array', 'min:1'],
            'job_categories.*' => ['integer', Rule::exists('job_categories', 'id')->where('is_active', true)],
            'total_experience_years' => ['required', 'numeric', 'min:0', 'max:60'],
            'skills' => ['nullable', 'array'],
            'skills.*.selected' => ['nullable', 'boolean'],
            'skills.*.years' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'skills.*.level' => ['nullable', Rule::in(['basic', 'intermediate', 'expert'])],
        ], ['job_categories.required' => __('Choose at least one job category.')]);

        $skills = collect($data['skills'] ?? [])
            ->filter(fn ($s) => ! empty($s['selected']))
            ->mapWithKeys(fn ($s, $id) => [(int) $id => ['years' => $s['years'] ?? 0, 'level' => $s['level'] ?? 'intermediate']]);

        // Only accept real, active skill IDs.
        $valid = \App\Models\Skill::whereIn('id', $skills->keys())->where('is_active', true)->pluck('id');
        $skills = $skills->only($valid->all());

        DB::transaction(function () use ($profile, $data, $skills) {
            $profile->jobCategories()->sync($data['job_categories']);
            $profile->skills()->sync($skills->all());
            $profile->update(['total_experience_years' => $data['total_experience_years']]);
        });

        Audit::log('applicant.profile_updated', $profile, meta: ['section' => 'skills', 'skills' => $skills->count()]);

        return $this->done($profile, 'skills', __('Skills and experience saved.'));
    }

    // ---- Preferences & availability ---------------------------------------------

    public function editPreferences(Request $request): View
    {
        $profile = $this->profile($request)->load('preferredDistricts');

        return view('applicant.profile.preferences', [
            'profile' => $profile,
            'districts' => District::orderBy('province')->orderBy('name_en')->get()->groupBy('province'),
            'selected' => $profile->preferredDistricts->pluck('id')->all(),
        ]);
    }

    public function updatePreferences(Request $request): RedirectResponse
    {
        $profile = $this->profile($request);

        $data = $request->validate([
            'districts' => ['required', 'array', 'min:1'],
            'districts.*' => ['integer', 'exists:districts,id'],
            'availability_status' => ['required', Rule::in([AvailabilityStatus::Available->value, AvailabilityStatus::AvailableFrom->value, AvailabilityStatus::Unavailable->value])],
            'available_from' => ['nullable', 'required_if:availability_status,available_from', 'date', 'after_or_equal:today'],
        ], ['districts.required' => __('Choose at least one district where you are willing to work.')]);

        DB::transaction(function () use ($profile, $data) {
            $profile->preferredDistricts()->sync($data['districts']);

            // "Engaged" is set by WaveTeam when assigned to a project; applicants can't override it.
            if ($profile->availability_status === AvailabilityStatus::Engaged) {
                return;
            }

            $profile->update([
                'availability_status' => $data['availability_status'],
                'available_from' => $data['availability_status'] === 'available_from' ? $data['available_from'] : null,
            ]);
        });

        Audit::log('applicant.profile_updated', $profile, meta: ['section' => 'preferences']);

        return $this->done($profile, 'preferences', __('Preferences saved.'));
    }
}
