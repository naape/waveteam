<x-layouts.auth :title="__('Sign in')" :portal="$portal" :heading="__('Sign in to your account')"
    :subheading="$portal === \App\Enums\Portal::Applicant ? __('Track your applications, documents and assignments.') : __('Manage your service requests and quotations.')">

    <form method="POST" action="{{ $portal->route('login') }}" class="space-y-5" novalidate>
        @csrf
        <x-form.input name="email" type="email" :label="__('Email address')" autocomplete="username" autofocus required />

        <div>
            <x-form.input name="password" type="password" :label="__('Password')" autocomplete="current-password" required />
            <div class="mt-2 text-right">
                <a href="{{ $portal->route('password.request') }}" class="text-sm font-medium text-navy-600 hover:text-navy-800">{{ __('Forgot your password?') }}</a>
            </div>
        </div>

        <x-form.checkbox name="remember">{{ __('Keep me signed in on this device') }}</x-form.checkbox>

        <button type="submit" class="btn-primary w-full !py-3">{{ __('Sign in') }}</button>
    </form>

    <p class="mt-8 text-center text-sm text-slate-600">
        {{ __('New to WaveTeam?') }}
        <a href="{{ $portal->route('register') }}" class="font-semibold text-navy-600 hover:text-navy-800">
            {{ $portal === \App\Enums\Portal::Applicant ? __('Create an applicant account') : __('Register your organisation') }}
        </a>
    </p>

    <p class="mt-3 text-center text-xs text-slate-500">
        @if ($portal === \App\Enums\Portal::Applicant)
            {{ __('Requesting services for a company?') }} <a href="{{ route('organization.login') }}" class="underline hover:text-slate-700">{{ __('Organisation sign in') }}</a>
        @else
            {{ __('Looking for work?') }} <a href="{{ route('applicant.login') }}" class="underline hover:text-slate-700">{{ __('Applicant sign in') }}</a>
        @endif
    </p>
</x-layouts.auth>
