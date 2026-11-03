<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ __('WaveTeam Portal') }}</title>
    <meta name="description" content="{{ $description ?? __('WaveTeam Engineering careers and service request portal.') }}">
    <link rel="icon" type="image/png" sizes="64x64" href="{{ asset('images/favicon.png') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-slate-50 font-sans text-slate-800">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded focus:bg-white focus:px-4 focus:py-2">
        {{ __('Skip to content') }}
    </a>

    <header class="absolute inset-x-0 top-0 z-30">
        <nav class="mx-auto flex max-w-7xl items-center justify-between px-4 py-5 sm:px-6 lg:px-8" aria-label="{{ __('Main') }}">
            <a href="{{ route('home') }}" class="flex items-center gap-3">
                <span class="grid h-11 w-11 place-items-center rounded-xl bg-white shadow-sm ring-1 ring-black/5">
                    <img src="{{ asset('images/waveteam-mark.png') }}" alt="" class="h-9 w-9 object-contain">
                </span>
                <span class="leading-tight text-white">
                    <span class="block text-base font-bold tracking-tight">WaveTeam</span>
                    <span class="block text-[11px] font-medium uppercase tracking-[0.18em] text-teal-300">{{ __('Engineering Portal') }}</span>
                </span>
            </a>
            <div class="flex items-center gap-2 sm:gap-3">
                <a href="{{ config('portal.company.website') }}" class="hidden text-sm font-medium text-white/80 hover:text-white lg:inline">
                    waveteam.lk
                </a>
                <a href="{{ route('applicant.login') }}" class="hidden text-sm font-medium text-white/80 hover:text-white md:inline">{{ __('Applicant sign in') }}</a>
                <a href="{{ route('organization.login') }}" class="btn-ghost !px-4 !py-2">{{ __('Organisation sign in') }}</a>
            </div>
        </nav>
    </header>

    <main id="main">
        {{ $slot }}
    </main>

    <footer class="border-t border-slate-200 bg-white">
        <div class="mx-auto grid max-w-7xl gap-8 px-4 py-10 sm:px-6 md:grid-cols-3 lg:px-8">
            <div>
                <p class="font-semibold text-navy-800">{{ config('portal.company.name') }}</p>
                <p class="mt-2 text-sm text-slate-500">{{ config('portal.company.address') }}</p>
            </div>
            <div class="text-sm text-slate-600">
                <p><a class="hover:text-navy-700" href="mailto:{{ config('portal.company.email') }}">{{ config('portal.company.email') }}</a></p>
                <p class="mt-1">{{ config('portal.company.phone') }}</p>
            </div>
            <div class="flex flex-wrap gap-x-5 gap-y-2 text-sm text-slate-500 md:justify-end">
                <a href="{{ route('policy', 'privacy-policy') }}" class="hover:text-navy-700">{{ __('Privacy Policy') }}</a>
                <a href="{{ route('policy', 'terms') }}" class="hover:text-navy-700">{{ __('Terms of Use') }}</a>
                <a href="{{ route('policy', 'data-retention') }}" class="hover:text-navy-700">{{ __('Data Retention') }}</a>
                <a href="{{ url(config('portal.admin_path')) }}" class="hover:text-navy-700">{{ __('Staff login') }}</a>
            </div>
        </div>
        <div class="border-t border-slate-100 py-4 text-center text-xs text-slate-400">
            @include('partials.copyright')
        </div>
    </footer>
</body>
</html>
