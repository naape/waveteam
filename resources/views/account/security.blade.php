<x-layouts.portal :title="__('Account security')" :portal="$portal">
    <div class="mb-8">
        <h1 class="text-2xl font-bold tracking-tight text-navy-900">{{ __('Account security') }}</h1>
        <p class="mt-1 text-slate-600">{{ __('Manage your password and the devices signed in to your account.') }}</p>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- Change password --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <h2 class="font-semibold text-navy-900">{{ __('Change password') }}</h2>
            <p class="mt-1 text-sm text-slate-500">{{ __('Changing your password signs you out of all other devices.') }}</p>

            <form method="POST" action="{{ route('account.password') }}" class="mt-6 space-y-4" novalidate>
                @csrf
                @method('PUT')
                <x-form.input name="current_password" type="password" bag="password" id="current_password" :label="__('Current password')" autocomplete="current-password" required />
                <x-form.input name="password" type="password" bag="password" id="new_password" :label="__('New password')" :hint="__('At least 10 characters, with letters and numbers')" autocomplete="new-password" required />
                <x-form.input name="password_confirmation" type="password" bag="password" id="new_password_confirmation" :label="__('Confirm new password')" autocomplete="new-password" required />
                <button type="submit" class="btn-primary">{{ __('Update password') }}</button>
            </form>
        </section>

        {{-- Sessions --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <h2 class="font-semibold text-navy-900">{{ __('Signed-in devices') }}</h2>
            <ul class="mt-4 divide-y divide-slate-100">
                @foreach ($sessions as $session)
                    <li class="flex items-center gap-3 py-3">
                        <span class="grid h-9 w-9 place-items-center rounded-lg bg-slate-100 text-slate-500">@svg('heroicon-o-computer-desktop', 'h-5 w-5')</span>
                        <div class="min-w-0 flex-1 text-sm">
                            <p class="font-medium text-slate-800">
                                {{ $session->agent }}
                                @if ($session->current)
                                    <span class="ml-1 rounded-full bg-volt-100 px-2 py-0.5 text-xs font-medium text-volt-700">{{ __('This device') }}</span>
                                @endif
                            </p>
                            <p class="text-slate-500">{{ $session->ip }} · {{ __('Active :time', ['time' => $session->last_active->diffForHumans()]) }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>

            @if ($sessions->count() > 1)
                <form method="POST" action="{{ route('account.sessions.destroy') }}" class="mt-4 space-y-3 border-t border-slate-100 pt-4" novalidate>
                    @csrf
                    @method('DELETE')
                    <x-form.input name="password" type="password" bag="sessions" id="sessions_password" :label="__('Confirm with your password')" autocomplete="current-password" required />
                    <button type="submit" class="btn-outline">{{ __('Sign out other devices') }}</button>
                </form>
            @endif
        </section>

        {{-- Activity --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-6 lg:col-span-2">
            <h2 class="font-semibold text-navy-900">{{ __('Recent security activity') }}</h2>
            @php
                $labels = [
                    'auth.login' => __('Signed in'),
                    'auth.logout' => __('Signed out'),
                    'auth.password_reset' => __('Password reset via email'),
                    'account.password_changed' => __('Password changed'),
                    'account.sessions_revoked' => __('Signed out other devices'),
                ];
            @endphp
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wider text-slate-500">
                        <tr><th class="py-2 pr-4 font-medium">{{ __('Event') }}</th><th class="py-2 pr-4 font-medium">{{ __('IP address') }}</th><th class="py-2 font-medium">{{ __('When') }}</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($activity as $entry)
                            <tr>
                                <td class="py-2.5 pr-4 text-slate-800">{{ $labels[$entry->action] ?? $entry->action }}</td>
                                <td class="py-2.5 pr-4 text-slate-500">{{ $entry->ip }}</td>
                                <td class="py-2.5 text-slate-500" title="{{ $entry->created_at }}">{{ $entry->created_at->diffForHumans() }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="py-4 text-slate-500">{{ __('No activity yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="mt-4 text-xs text-slate-500">{{ __('Don’t recognise something? Change your password and contact :email.', ['email' => config('portal.company.email')]) }}</p>
        </section>
    </div>
</x-layouts.portal>
