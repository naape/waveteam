<x-layouts.auth :title="__('Choose a new password')" :heading="__('Choose a new password')">
    <form method="POST" action="{{ route('password.update') }}" class="space-y-5" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-form.input name="email" type="email" :label="__('Email address')" :value="$email" autocomplete="username" required />
        <x-form.input name="password" type="password" :label="__('New password')" :hint="__('At least 10 characters, with letters and numbers')" autocomplete="new-password" autofocus required />
        <x-form.input name="password_confirmation" type="password" :label="__('Confirm new password')" autocomplete="new-password" required />
        <button type="submit" class="btn-primary w-full !py-3">{{ __('Reset password') }}</button>
    </form>
</x-layouts.auth>
