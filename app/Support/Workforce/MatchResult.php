<?php

namespace App\Support\Workforce;

use App\Models\ApplicantProfile;

/**
 * One candidate for a service request, with an explainable score.
 */
final class MatchResult
{
    /**
     * @param  array<string, float>  $breakdown  factor => 0..1
     * @param  array<int, string>  $reasons  human-readable explanation
     * @param  array<int, string>  $warnings  things staff should check
     */
    public function __construct(
        public readonly ApplicantProfile $profile,
        public readonly int $score,
        public readonly array $breakdown,
        public readonly array $reasons,
        public readonly array $warnings = [],
    ) {}
}
