<?php

namespace App\Rules;

use App\Models\ApplicantProfile;
use App\Support\BlindIndex;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Sri Lankan NIC: old (9 digits + V/X) or new (12 digits) format, and not
 * already registered. Uniqueness is checked via the blind index, so the
 * encrypted NIC never needs decrypting.
 */
class NicNumberRule implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreProfileId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! BlindIndex::isValidNic((string) $value)) {
            $fail(__('Enter a valid NIC number (e.g. 901234567V or 199012345678).'));

            return;
        }

        $exists = ApplicantProfile::withTrashed()
            ->where('nic_hash', BlindIndex::nic((string) $value))
            ->when($this->ignoreProfileId, fn ($q) => $q->whereKeyNot($this->ignoreProfileId))
            ->exists();

        if ($exists) {
            $fail(__('An account with this NIC number already exists. Please sign in or reset your password.'));
        }
    }
}
