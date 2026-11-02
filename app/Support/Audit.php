<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Append-only audit trail. Sensitive attribute values are never written.
 */
class Audit
{
    /** Attributes whose values must never appear in the audit log. */
    public const REDACTED = [
        'password', 'remember_token', 'nic_number', 'passport_number', 'certificate_number',
        'licence_number',
        'app_authentication_secret', 'app_authentication_recovery_codes', 'nic_hash',
    ];

    public static function log(
        string $action,
        ?Model $subject = null,
        array $old = [],
        array $new = [],
        array $meta = [],
    ): AuditLog {
        $user = auth()->user();
        $request = app()->runningInConsole() ? null : request();

        return AuditLog::create([
            'actor_id' => $user?->getKey(),
            'actor_type' => $user?->user_type?->value ?? 'system',
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'old_values' => self::redact($old) ?: null,
            'new_values' => self::redact($new) ?: null,
            'meta' => $meta ?: null,
            'ip' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 500) : null,
        ]);
    }

    public static function redact(array $values): array
    {
        foreach (self::REDACTED as $key) {
            if (Arr::has($values, $key)) {
                $values[$key] = '[redacted]';
            }
        }

        return $values;
    }
}
