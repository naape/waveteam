@props(['title', 'portal' => null, 'heading', 'subheading' => null, 'wide' => false])

@php
    $isOrg = $portal === \App\Enums\Portal::Organization;
    $pitch = $isOrg
        ? ['tag' => __('Service Request Portal'), 'title' => __('Engineering services and verified manpower, on request.'), 'points' => [__('Submit detailed service requests'), __('Review and accept quotations online'), __('Follow your project to completion')]]
        : ['tag' => __('Applicant Portal'), 'title' => __('Build your career with WaveTeam Engineering.'), 'points' => [__('Apply for engineering & technical roles'), __('Keep your certificates in one secure place'), __('Get notified about interviews and assignments')]];
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} · {{ __('WaveTeam Portal') }}</title>
    <link rel="icon" type="image/png" sizes="64x64" href="{{ asset('images/favicon.png') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-white font-sans text-slate-800">
    <div class="flex min-h-full">
        {{-- Brand panel (desktop) --}}
        <aside class="bg-blueprint relative hidden w-[42%] max-w-xl flex-col justify-between overflow-hidden p-10 text-white lg:flex">
            <a href="{{ route('home') }}" class="flex items-center gap-3">
                <span class="grid h-11 w-11 place-items-center rounded-xl bg-white shadow-sm">
                    <img src="{{ asset('images/waveteam-mark.png') }}" alt="" class="h-9 w-9 object-contain">
                </span>
                <span class="leading-tight">
                    <span class="block font-bold">WaveTeam</span>
                    <span class="block text-[11px] font-medium uppercase tracking-[0.18em] text-teal-300">{{ __('Engineering Portal') }}</span>
                </span>
            </a>

            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-teal-300">{{ $pitch['tag'] }}</p>
                <p class="mt-3 text-3xl font-bold leading-tight tracking-tight">{{ $pitch['title'] }}</p>
                <ul class="mt-8 space-y-3 text-slate-300">
                    @foreach ($pitch['points'] as $point)
                        <li class="flex gap-3">@svg('heroicon-s-check-circle', 'mt-0.5 h-5 w-5 shrink-0 text-volt-400') {{ $point }}</li>
                    @endforeach
                </ul>
            </div>

            <p class="flex items-center gap-2 text-xs text-slate-400">
                @svg('heroicon-o-lock-closed', 'h-4 w-4') {{ __('Your information is encrypted and only visible to authorised WaveTeam staff.') }}
            </p>

            <svg class="pointer-events-none absolute inset-x-0 bottom-28 w-full text-teal-400/25" viewBox="0 0 600 80" fill="none" preserveAspectRatio="none" aria-hidden="true">
                <path d="M0 50 H230 L250 50 L265 12 L285 72 L302 28 L318 50 H600" stroke="currentColor" stroke-width="2" vector-effect="non-scaling-stroke"/>
            </svg>
        </aside>

        {{-- Form panel --}}
        <main class="flex min-w-0 flex-1 flex-col">
            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-4 sm:px-8 lg:border-0">
                <a href="{{ route('home') }}" class="flex items-center gap-2 lg:invisible">
                    <img src="{{ asset('images/waveteam-mark.png') }}" alt="WaveTeam" class="h-9 w-9 object-contain">
                    <span class="font-bold text-navy-800">WaveTeam</span>
                </a>
                <a href="{{ route('home') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-navy-700">
                    @svg('heroicon-m-arrow-left', 'h-4 w-4') {{ __('Home') }}
                </a>
            </div>

            <div class="flex flex-1 items-start justify-center px-4 py-8 sm:px-8 sm:py-12 lg:items-center">
                <div class="w-full {{ $wide ? 'max-w-2xl' : 'max-w-md' }}">
                    @if ($portal)
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-teal-600">{{ $portal->label() }}</p>
                    @endif
                    <h1 class="mt-2 text-2xl font-bold tracking-tight text-navy-900 sm:text-3xl">{{ $heading }}</h1>
                    @if ($subheading)
                        <p class="mt-2 text-slate-600">{{ $subheading }}</p>
                    @endif

                    <x-status-alert class="mt-6" />

                    <div class="mt-8">
                        {{ $slot }}
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
