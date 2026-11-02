@props(['title', 'portal'])

@php
    $nav = $portal === \App\Enums\Portal::Applicant
        ? [
            ['applicant.dashboard', 'heroicon-o-squares-2x2', __('Dashboard'), 'applicant.dashboard'],
            ['applicant.profile.personal', 'heroicon-o-user-circle', __('My profile'), 'applicant.profile.*'],
            ['applicant.documents.index', 'heroicon-o-folder', __('Documents'), 'applicant.documents.*'],
            ['applicant.police-clearance.index', 'heroicon-o-shield-check', __('Police clearance'), 'applicant.police-clearance.*'],
            ['applicant.vacancies.index', 'heroicon-o-briefcase', __('Vacancies'), 'applicant.vacancies.*'],
            ['applicant.applications.index', 'heroicon-o-clipboard-document-check', __('My applications'), 'applicant.applications.*'],
        ]
        : [
            ['organization.dashboard', 'heroicon-o-squares-2x2', __('Dashboard'), 'organization.dashboard'],
            ['organization.requests.index', 'heroicon-o-clipboard-document-list', __('Service requests'), ['organization.requests.index', 'organization.requests.show']],
            ['organization.requests.create', 'heroicon-o-plus-circle', __('New request'), 'organization.requests.create'],
            ['organization.profile', 'heroicon-o-building-office-2', __('Organisation'), 'organization.profile*'],
        ];
    $nav[] = ['account.security', 'heroicon-o-lock-closed', __('Account'), 'account.*'];
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} · {{ $portal->label() }}</title>
    <link rel="icon" type="image/png" sizes="64x64" href="{{ asset('images/favicon.png') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-slate-50 font-sans text-slate-800">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8">
            <a href="{{ $portal->dashboardUrl() }}" class="flex min-w-0 items-center gap-3">
                <img src="{{ asset('images/waveteam-mark.png') }}" alt="WaveTeam" class="h-10 w-10 shrink-0 object-contain">
                <span class="min-w-0 leading-tight">
                    <span class="block font-bold text-navy-800">WaveTeam</span>
                    <span class="block truncate text-xs text-slate-500">{{ $portal->label() }}</span>
                </span>
            </a>

            <div class="flex items-center gap-3">
                <span class="hidden text-right text-sm leading-tight sm:block">
                    <span class="block font-medium text-slate-800">{{ auth()->user()->name }}</span>
                    <span class="block text-xs text-slate-500">{{ auth()->user()->email }}</span>
                </span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="btn-outline !px-3 !py-2" title="{{ __('Sign out') }}">
                        @svg('heroicon-o-arrow-right-on-rectangle', 'h-4 w-4')
                        <span class="hidden sm:inline">{{ __('Sign out') }}</span>
                    </button>
                </form>
            </div>
        </div>

        <nav class="mx-auto max-w-7xl overflow-x-auto px-4 sm:px-6 lg:px-8" aria-label="{{ __('Portal') }}">
            <ul class="flex gap-1">
                @foreach ($nav as [$route, $icon, $label, $pattern])
                    @php $active = request()->routeIs(...(array) $pattern); @endphp
                    <li>
                        <a href="{{ route($route) }}" @if ($active) aria-current="page" @endif
                           class="inline-flex items-center gap-2 whitespace-nowrap border-b-2 px-3 py-3 text-sm font-medium {{ $active ? 'border-navy-600 text-navy-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' }}">
                            @svg($icon, 'h-4 w-4') {{ $label }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <x-status-alert class="mb-6" />
        {{ $slot }}
    </main>

    <footer class="mx-auto max-w-7xl px-4 pb-10 text-xs text-slate-400 sm:px-6 lg:px-8">
        <a href="{{ route('policy', 'privacy-policy') }}" class="hover:text-slate-600">{{ __('Privacy Policy') }}</a> ·
        <a href="{{ route('policy', 'terms') }}" class="hover:text-slate-600">{{ __('Terms of Use') }}</a> ·
        &copy; {{ date('Y') }} {{ config('portal.company.name') }}
    </footer>
</body>
</html>
