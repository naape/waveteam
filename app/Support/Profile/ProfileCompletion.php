<?php

namespace App\Support\Profile;

use App\Enums\DocumentType;
use App\Models\ApplicantProfile;

/**
 * Weighted profile completeness (0–100) plus the checklist of what is missing.
 */
class ProfileCompletion
{
    /**
     * @return array<string, array{label:string, weight:int, done:bool, route:string}>
     */
    public static function checklist(ApplicantProfile $p): array
    {
        $p->loadCount(['educations', 'skills', 'jobCategories', 'employments', 'languages', 'preferredDistricts']);
        $docs = $p->documents()->where('is_current', true)->pluck('document_type')
            ->map(fn ($t) => $t instanceof DocumentType ? $t->value : $t)->all();

        $personal = filled($p->date_of_birth) && filled($p->permanent_address) && filled($p->current_address)
            && filled($p->district_id) && filled($p->contact_number)
            && filled($p->emergency_contact_name) && filled($p->emergency_contact_number);

        return [
            'personal' => ['label' => __('Personal details'), 'weight' => 20, 'done' => $personal, 'route' => 'applicant.profile.personal'],
            'photo' => ['label' => __('Profile photo'), 'weight' => 5, 'done' => filled($p->photo_file_id), 'route' => 'applicant.profile.personal'],
            'education' => ['label' => __('Education'), 'weight' => 10, 'done' => $p->educations_count > 0, 'route' => 'applicant.profile.section'],
            'skills' => ['label' => __('Job categories & skills'), 'weight' => 15, 'done' => $p->skills_count > 0 && $p->job_categories_count > 0, 'route' => 'applicant.profile.skills'],
            'employment' => ['label' => __('Employment history'), 'weight' => 10, 'done' => $p->employments_count > 0, 'route' => 'applicant.profile.section'],
            'languages' => ['label' => __('Languages'), 'weight' => 5, 'done' => $p->languages_count > 0, 'route' => 'applicant.profile.section'],
            'preferences' => ['label' => __('Work preferences & availability'), 'weight' => 5, 'done' => $p->preferred_districts_count > 0, 'route' => 'applicant.profile.preferences'],
            'cv' => ['label' => __('CV / résumé'), 'weight' => 10, 'done' => in_array('cv', $docs, true), 'route' => 'applicant.documents.index'],
            'nic' => ['label' => __('NIC copy'), 'weight' => 10, 'done' => in_array('nic', $docs, true), 'route' => 'applicant.documents.index'],
            'pcc' => ['label' => __('Police clearance certificate'), 'weight' => 10, 'done' => in_array('police_clearance', $docs, true), 'route' => 'applicant.police-clearance.index'],
        ];
    }

    public static function score(ApplicantProfile $p): int
    {
        return (int) collect(self::checklist($p))->where('done', true)->sum('weight');
    }

    /** Recalculate and persist (profile_completion is guarded, so set explicitly). */
    public static function refresh(ApplicantProfile $p): int
    {
        $score = self::score($p);

        if ($p->profile_completion !== $score) {
            $p->forceFill(['profile_completion' => $score])->saveQuietly();
        }

        return $score;
    }
}
