<?php

namespace App\Actions\Admin;

use App\Actions\Applicant\SubmitPoliceClearance;
use App\Actions\Compliance\PoliceClearanceVerification;
use App\Enums\AvailabilityStatus;
use App\Models\ApplicantProfile;
use App\Models\PoliceClearanceRecord;
use App\Models\User;
use App\Support\Audit;
use App\Support\Profile\ProfileCompletion;
use App\Support\Profile\ProfileSections;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Staff edits to an applicant's qualification sections (education,
 * certifications, employment, languages, licences, military service)
 * and to their police clearance certificates. Everything is audited.
 */
class ApplicantRecordsActions
{
    public function __construct(
        private readonly SubmitPoliceClearance $submitPcc,
        private readonly PoliceClearanceVerification $verification,
    ) {}

    /** Adds ($entry = null) or updates an entry in one of the ProfileSections. */
    public function saveEntry(ApplicantProfile $profile, string $sectionKey, ?Model $entry, array $data, User $staff): Model
    {
        $section = ProfileSections::get($sectionKey);
        $relation = $profile->{$section['relation']}();

        if ($entry && (int) $entry->applicant_profile_id !== (int) $profile->id) {
            abort(404);
        }

        $data = Validator::make($data, ProfileSections::rules($section, $relation->getRelated()->getTable(), $profile->id, $entry?->id))->validate();
        $data = ProfileSections::normalize($section, $data);

        if ($entry) {
            $entry->update($data);
            Audit::log('applicant.profile_entry_updated', $entry, meta: ['section' => $sectionKey, 'by_staff' => true, 'applicant_profile_id' => $profile->id]);
        } else {
            $entry = $relation->create($data);
            Audit::log('applicant.profile_entry_added', $entry, meta: ['section' => $sectionKey, 'by_staff' => true, 'applicant_profile_id' => $profile->id]);
        }

        ProfileCompletion::refresh($profile);

        return $entry;
    }

    public function deleteEntry(ApplicantProfile $profile, string $sectionKey, Model $entry, User $staff): void
    {
        abort_unless((int) $entry->applicant_profile_id === (int) $profile->id, 404);

        $entry->delete();
        Audit::log('applicant.profile_entry_removed', $entry, meta: ['section' => $sectionKey, 'by_staff' => true, 'applicant_profile_id' => $profile->id]);
        ProfileCompletion::refresh($profile);
    }

    /**
     * Job categories, total experience and skills (with years and level).
     *
     * @param  array{job_categories: int[], total_experience_years: float|int|string, skills?: array<int, array{skill_id: int, years?: mixed, level?: string}>}  $data
     */
    public function updateSkills(ApplicantProfile $profile, array $data, User $staff, string $reason): void
    {
        $data = Validator::make($data, [
            'job_categories' => ['required', 'array', 'min:1'],
            'job_categories.*' => ['integer', Rule::exists('job_categories', 'id')->where('is_active', true)],
            'total_experience_years' => ['required', 'numeric', 'min:0', 'max:60'],
            'skills' => ['nullable', 'array', 'max:60'],
            'skills.*.skill_id' => ['required', 'integer', 'distinct', Rule::exists('skills', 'id')->where('is_active', true)],
            'skills.*.years' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'skills.*.level' => ['nullable', Rule::in(['basic', 'intermediate', 'expert'])],
        ], [
            'job_categories.required' => __('Choose at least one job category.'),
            'skills.*.skill_id.distinct' => __('Each skill can be listed only once.'),
        ])->validate();

        $skills = collect($data['skills'] ?? [])->mapWithKeys(fn ($s) => [(int) $s['skill_id'] => [
            'years' => $s['years'] ?? 0, 'level' => $s['level'] ?? 'intermediate',
        ]]);

        $before = $this->skillsSnapshot($profile);

        DB::transaction(function () use ($profile, $data, $skills) {
            $profile->jobCategories()->sync($data['job_categories']);
            $profile->skills()->sync($skills->all());
            $profile->update(['total_experience_years' => $data['total_experience_years']]);
        });

        Audit::log('applicant.skills_updated_by_staff', $profile, $before, $this->skillsSnapshot($profile->fresh()), ['reason' => $reason]);
        ProfileCompletion::refresh($profile);
    }

    private function skillsSnapshot(ApplicantProfile $profile): array
    {
        return [
            'job_categories' => $profile->jobCategories()->orderBy('job_categories.id')->pluck('name')->all(),
            'total_experience_years' => (float) $profile->total_experience_years,
            'skills' => $profile->skills()->orderBy('skills.name')->get()
                ->map(fn ($s) => "{$s->name} ({$s->pivot->years}y, {$s->pivot->level})")->all(),
        ];
    }

    /**
     * Districts willing to work in, and availability. "Engaged" is controlled
     * by workforce assignments, so it cannot be changed here.
     *
     * @param  array{districts: int[], availability_status?: string, available_from?: ?string}  $data
     */
    public function updatePreferences(ApplicantProfile $profile, array $data, User $staff, string $reason): void
    {
        $engaged = $profile->availability_status === AvailabilityStatus::Engaged;

        $data = Validator::make($data, [
            'districts' => ['required', 'array', 'min:1'],
            'districts.*' => ['integer', 'exists:districts,id'],
            'availability_status' => [$engaged ? 'nullable' : 'required', Rule::in([AvailabilityStatus::Available->value, AvailabilityStatus::AvailableFrom->value, AvailabilityStatus::Unavailable->value])],
            'available_from' => ['nullable', 'required_if:availability_status,available_from', 'date', 'after_or_equal:today'],
        ], ['districts.required' => __('Choose at least one district.')])->validate();

        $before = $this->preferencesSnapshot($profile);

        DB::transaction(function () use ($profile, $data, $engaged) {
            $profile->preferredDistricts()->sync($data['districts']);

            if (! $engaged) {
                $profile->update([
                    'availability_status' => $data['availability_status'],
                    'available_from' => $data['availability_status'] === AvailabilityStatus::AvailableFrom->value ? $data['available_from'] : null,
                ]);
            }
        });

        Audit::log('applicant.preferences_updated_by_staff', $profile, $before, $this->preferencesSnapshot($profile->fresh()), ['reason' => $reason]);
        ProfileCompletion::refresh($profile);
    }

    private function preferencesSnapshot(ApplicantProfile $profile): array
    {
        return [
            'districts' => $profile->preferredDistricts()->orderBy('name_en')->pluck('name_en')->all(),
            'availability_status' => $profile->availability_status?->value,
            'available_from' => $profile->available_from?->toDateString(),
        ];
    }

    /**
     * Uploads a certificate on the applicant's behalf. It starts as Pending
     * Verification like any upload; a verifier may approve it in the same step.
     *
     * @param  array{valid_until: Carbon, renewal_due?: ?Carbon, notes?: ?string}|null  $approve
     */
    public function uploadPoliceClearance(ApplicantProfile $profile, UploadedFile $file, Carbon $issueDate, User $staff,
        ?string $certificateNumber = null, ?string $authority = null, ?array $approve = null): PoliceClearanceRecord
    {
        if ($issueDate->isFuture()) {
            throw ValidationException::withMessages(['issue_date' => __('The issue date cannot be in the future.')]);
        }

        $record = ($this->submitPcc)($profile, $file, $issueDate, $staff, $certificateNumber, $authority);
        Audit::log('pcc.uploaded_by_staff', $record, meta: ['applicant_profile_id' => $profile->id]);

        if ($approve) {
            // Throws (and leaves the upload pending) if the dates are invalid.
            $this->verification->approve($record->fresh(), $staff, $approve['valid_until'], $approve['renewal_due'] ?? null, $approve['notes'] ?? null);
        }

        return $record->fresh();
    }

    /**
     * Takes a certificate out of use. It is NOT deleted: the record and file
     * stay in the history, marked as removed with who, when and why.
     */
    public function removePoliceClearance(PoliceClearanceRecord $record, User $staff, string $reason): void
    {
        abort_unless($staff->can('police_clearance.verify'), 403);

        if ($record->removed_at) {
            throw ValidationException::withMessages(['record' => __('This certificate has already been removed.')]);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('A reason is required.')]);
        }

        $profile = $record->applicantProfile;
        $wasCurrent = (int) $profile->current_police_clearance_id === (int) $record->id;

        DB::transaction(function () use ($record, $staff, $reason, $profile, $wasCurrent) {
            $record->forceFill([
                'removed_at' => now(),
                'removed_by' => $staff->id,
                'removal_reason' => $reason,
                'superseded_at' => $record->superseded_at ?? now(), // out of every "current" query
            ])->save();

            $record->document?->forceFill(['is_current' => false])->save();

            if ($wasCurrent) {
                $profile->forceFill(['current_police_clearance_id' => null])->save();
            }
        });

        Audit::log('pcc.removed', $record, meta: ['reason' => $reason, 'was_in_force' => $wasCurrent, 'applicant_profile_id' => $profile->id]);
    }
}
