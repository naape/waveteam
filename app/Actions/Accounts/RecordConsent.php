<?php

namespace App\Actions\Accounts;

use App\Models\ConsentRecord;
use App\Models\PolicyVersion;
use App\Models\User;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Stores proof of consent (which policy version, when, from where) — PDPA 2022.
 */
class RecordConsent
{
    public const REQUIRED = ['privacy', 'terms'];

    public function __invoke(User $user, ?Request $request = null, array $types = self::REQUIRED): void
    {
        foreach ($types as $type) {
            $policy = PolicyVersion::current($type);

            if ($policy === null) {
                throw new RuntimeException("No published {$type} policy — cannot record consent.");
            }

            ConsentRecord::create([
                'user_id' => $user->id,
                'policy_version_id' => $policy->id,
                'consented_at' => now(),
                'ip' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 500) : null,
            ]);
        }
    }
}
