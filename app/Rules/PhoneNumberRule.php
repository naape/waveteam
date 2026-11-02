<?php

namespace App\Rules;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PhoneNumberRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! PhoneNumber::isValid((string) $value)) {
            $fail(__('Enter a valid phone number, e.g. 077 123 4567 or +94 77 123 4567.'));
        }
    }
}
