<x-layouts.auth :title="__('Forgot password')" :portal="$portal" :heading="__('Reset your password')"
    :subheading="__('Enter the email address you registered with and we will send you a reset link.')">

    <form method="POST" action="{{ $portal->route('password.email') }}" class="space-y-5" novalidate>
        @csrf
        <x-form.input name="email" type="email" :label="__('Email address')" autocomplete="email" autofocus required />
        <button type="submit" class="btn-primary w-full !py-3">{{ __('Email reset link') }}</button>
    </form>

    <p class="mt-8 text-center text-sm">
        <a href="{{ $portal->route('login') }}" class="font-semibold text-navy-600 hover:text-navy-800">{{ __('Back to sign in') }}</a>
    </p>
</x-layouts.auth>
