<?php

namespace App\Http\Requests\Auth;

use App\Enums\OrganizationType;
use App\Rules\PhoneNumberRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            'billing_same' => $this->boolean('billing_same'),
        ]);
    }

    public function rules(): array
    {
        return [
            'organization_name' => ['required', 'string', 'min:2', 'max:200'],
            'registration_number' => ['nullable', 'string', 'max:60'],
            'organization_type' => ['required', Rule::enum(OrganizationType::class)],
            'contact_name' => ['required', 'string', 'min:3', 'max:150'],
            'designation' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:190', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:25', new PhoneNumberRule],
            'address' => ['required', 'string', 'max:500'],
            'billing_same' => ['boolean'],
            'billing_address' => ['nullable', 'required_unless:billing_same,true', 'string', 'max:500'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'accept_terms' => ['accepted'],
            'accept_privacy' => ['accepted'],
        ];
    }

    public function attributes(): array
    {
        return [
            'organization_name' => __('organisation name'),
            'organization_type' => __('organisation type'),
            'contact_name' => __('contact person'),
            'accept_terms' => __('terms of use'),
            'accept_privacy' => __('privacy policy'),
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => __('An account with this email already exists. Please sign in or reset your password.'),
            'billing_address.required_unless' => __('Enter a billing address, or tick “same as address”.'),
        ];
    }
}
