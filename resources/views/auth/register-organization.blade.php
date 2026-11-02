<x-layouts.auth :title="__('Register organisation')" :portal="$portal" wide
    :heading="__('Register your organisation')"
    :subheading="__('Create an account to request WaveTeam services and technical manpower.')">

    <form method="POST" action="{{ route('organization.register') }}" class="space-y-8" novalidate>
        @csrf

        <fieldset class="grid gap-5 sm:grid-cols-2">
            <legend class="mb-4 text-sm font-semibold uppercase tracking-wider text-slate-500">{{ __('Organisation') }}</legend>
            <x-form.input class="sm:col-span-2" name="organization_name" :label="__('Organisation name')" autocomplete="organization" autofocus required />
            <x-form.select name="organization_type" :label="__('Organisation type')" :options="$organizationTypes" :placeholder="__('Select…')" required />
            <x-form.input name="registration_number" :label="__('Business registration no. (optional)')" />
            <x-form.textarea class="sm:col-span-2" name="address" :label="__('Address')" rows="2" autocomplete="street-address" required />
            <div class="sm:col-span-2">
                <input type="hidden" name="billing_same" value="0">
                <x-form.checkbox name="billing_same" :checked="true" id="billing_same">{{ __('Billing address is the same as the address above') }}</x-form.checkbox>
                <x-form.textarea class="mt-3" name="billing_address" :label="__('Billing address')" rows="2" />
            </div>
        </fieldset>

        <fieldset class="grid gap-5 sm:grid-cols-2">
            <legend class="mb-4 text-sm font-semibold uppercase tracking-wider text-slate-500">{{ __('Contact person') }}</legend>
            <x-form.input name="contact_name" :label="__('Full name')" autocomplete="name" required />
            <x-form.input name="designation" :label="__('Designation')" autocomplete="organization-title" required />
            <x-form.input name="email" type="email" :label="__('Email address')" :hint="__('Used to sign in')" autocomplete="email" required />
            <x-form.input name="phone" type="tel" :label="__('Contact number')" autocomplete="tel" required />
            <x-form.input name="password" type="password" :label="__('Password')" :hint="__('At least 10 characters, with letters and numbers')" autocomplete="new-password" required />
            <x-form.input name="password_confirmation" type="password" :label="__('Confirm password')" autocomplete="new-password" required />
        </fieldset>

        <fieldset class="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4 sm:p-5">
            <legend class="px-1 text-sm font-semibold text-navy-900">{{ __('Consent') }}</legend>
            <x-form.checkbox name="accept_terms">
                {!! __('I am authorised to register this organisation and agree to the :terms.', ['terms' => '<a href="'.e(route('policy', 'terms')).'" target="_blank" class="font-medium text-navy-600 underline">'.e(__('Terms of Use')).'</a>']) !!}
            </x-form.checkbox>
            <x-form.checkbox name="accept_privacy">
                {!! __('I have read the :privacy.', ['privacy' => '<a href="'.e(route('policy', 'privacy-policy')).'" target="_blank" class="font-medium text-navy-600 underline">'.e(__('Privacy Policy')).'</a>']) !!}
            </x-form.checkbox>
        </fieldset>

        <button type="submit" class="btn-primary w-full !py-3 sm:w-auto sm:!px-8">{{ __('Register organisation') }}</button>
    </form>

    <p class="mt-8 text-sm text-slate-600">
        {{ __('Already registered?') }}
        <a href="{{ route('organization.login') }}" class="font-semibold text-navy-600 hover:text-navy-800">{{ __('Sign in') }}</a>
    </p>
</x-layouts.auth>
