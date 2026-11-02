<x-layouts.auth :title="__('Verify your email')" :portal="$portal" :heading="__('Check your email')">
    <div class="rounded-xl border border-slate-200 bg-slate-50 p-5">
        <div class="flex gap-4">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-teal-400/15 text-teal-600">@svg('heroicon-o-envelope', 'h-6 w-6')</span>
            <p class="text-sm leading-relaxed text-slate-600">
                {!! __('We sent a verification link to :email. Click the link in that email to activate your account.', ['email' => '<strong class="text-slate-900">'.e(auth()->user()->email).'</strong>']) !!}
            </p>
        </div>
    </div>

    <p class="mt-6 text-sm text-slate-600">{{ __('Didn’t receive it? Check your spam folder, or send a new link.') }}</p>

    <div class="mt-4 flex flex-wrap items-center gap-3">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" class="btn-primary">{{ __('Resend verification email') }}</button>
        </form>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn-outline">{{ __('Sign out') }}</button>
        </form>
    </div>
</x-layouts.auth>
