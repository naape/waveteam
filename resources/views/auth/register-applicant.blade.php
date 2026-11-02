<x-layouts.auth :title="__('Create applicant account')" :portal="$portal" wide
    :heading="__('Create your applicant account')"
    :subheading="__('Start with the basics. You can complete your full profile, qualifications and documents after signing in.')">

    <form method="POST" action="{{ route('applicant.register') }}" class="space-y-6" novalidate>
        @csrf

        <fieldset class="grid gap-5 sm:grid-cols-2">
            <legend class="sr-only">{{ __('Personal details') }}</legend>
            <x-form.input class="sm:col-span-2" name="full_name" :label="__('Full name (as on NIC)')" autocomplete="name" autofocus required />
            <x-form.input name="nic_number" :label="__('NIC number')" :hint="__('Old (901234567V) or new (199012345678) format')" autocomplete="off" required />
            <x-form.input name="contact_number" type="tel" :label="__('Mobile number')" :hint="__('e.g. 077 123 4567')" autocomplete="tel" required />
            <x-form.input class="sm:col-span-2" name="email" type="email" :label="__('Email address')" autocomplete="email" required />
            <x-form.input name="password" type="password" :label="__('Password')" :hint="__('At least 10 characters, with letters and numbers')" autocomplete="new-password" required />
            <x-form.input name="password_confirmation" type="password" :label="__('Confirm password')" autocomplete="new-password" required />
        </fieldset>

        <fieldset class="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4 sm:p-5">
            <legend class="px-1 text-sm font-semibold text-navy-900">{{ __('Consent') }}</legend>
            <x-form.checkbox name="accept_terms">
                {!! __('I agree to the :terms.', ['terms' => '<a href="'.e(route('policy', 'terms')).'" target="_blank" class="font-medium text-navy-600 underline">'.e(__('Terms of Use')).'</a>']) !!}
            </x-form.checkbox>
            <x-form.checkbox name="accept_privacy">
                {!! __('I have read the :privacy and :retention, and consent to WaveTeam processing my personal data for recruitment and workforce management.', [
                    'privacy' => '<a href="'.e(route('policy', 'privacy-policy')).'" target="_blank" class="font-medium text-navy-600 underline">'.e(__('Privacy Policy')).'</a>',
                    'retention' => '<a href="'.e(route('policy', 'data-retention')).'" target="_blank" class="font-medium text-navy-600 underline">'.e(__('Data Retention Policy')).'</a>',
                ]) !!}
            </x-form.checkbox>
            <x-form.checkbox name="accept_sensitive">
                {{ __('I explicitly consent to WaveTeam collecting and verifying my NIC and police clearance certificate, which may be required by clients before I can be assigned to a site.') }}
            </x-form.checkbox>
        </fieldset>

        <button type="submit" class="btn-primary w-full !py-3 sm:w-auto sm:!px-8">{{ __('Create account') }}</button>
    </form>

    <p class="mt-8 text-sm text-slate-600">
        {{ __('Already registered?') }}
        <a href="{{ route('applicant.login') }}" class="font-semibold text-navy-600 hover:text-navy-800">{{ __('Sign in') }}</a>
    </p>
</x-layouts.auth>
