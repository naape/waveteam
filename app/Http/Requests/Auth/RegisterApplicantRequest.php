<?php

namespace App\Http\Requests\Auth;

use App\Rules\NicNumberRule;
use App\Rules\PhoneNumberRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterApplicantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            'full_name' => preg_replace('/\s+/', ' ', trim((string) $this->input('full_name'))),
        ]);
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'min:3', 'max:150'],
            'nic_number' => ['required', 'string', 'max:20', new NicNumberRule],
            'email' => ['required', 'string', 'email:rfc', 'max:190', 'unique:users,email'],
            'contact_number' => ['required', 'string', 'max:25', new PhoneNumberRule],
            'password' => ['required', 'confirmed', Password::defaults()],
            'accept_terms' => ['accepted'],
            'accept_privacy' => ['accepted'],
            'accept_sensitive' => ['accepted'],
        ];
    }

    public function attributes(): array
    {
        return [
            'nic_number' => __('NIC number'),
            'contact_number' => __('mobile number'),
            'accept_terms' => __('terms of use'),
            'accept_privacy' => __('privacy policy'),
            'accept_sensitive' => __('consent to process sensitive documents'),
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => __('An account with this email already exists. Please sign in or reset your password.'),
        ];
    }
}
