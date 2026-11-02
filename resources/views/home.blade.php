<x-layouts.public :title="__('Careers & Service Requests')">
    {{-- Hero --}}
    <section class="bg-blueprint relative overflow-hidden pb-40 pt-36 text-white sm:pb-48 sm:pt-44">
        {{-- Signal pulse motif from the logo --}}
        <svg class="pointer-events-none absolute inset-x-0 bottom-24 w-full text-teal-400/40" viewBox="0 0 1440 120" fill="none" preserveAspectRatio="none" aria-hidden="true">
            <path d="M0 70 H520 L560 70 L590 20 L625 110 L660 40 L690 70 H1440" stroke="currentColor" stroke-width="2.5" vector-effect="non-scaling-stroke"/>
        </svg>

        <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <p class="inline-flex items-center gap-2 rounded-full border border-teal-300/30 bg-teal-300/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em] text-teal-200">
                <span class="h-1.5 w-1.5 rounded-full bg-volt-400"></span>
                {{ __('WaveTeam Engineering') }}
            </p>
            <h1 class="mt-6 max-w-3xl text-4xl font-bold leading-[1.1] tracking-tight sm:text-5xl lg:text-6xl">
                {{ __('Powering Sri Lanka’s networks with') }}
                <span class="bg-gradient-to-r from-teal-300 to-volt-400 bg-clip-text text-transparent">{{ __('skilled, verified people.') }}</span>
            </h1>
            <p class="mt-6 max-w-2xl text-lg leading-relaxed text-slate-300">
                {{ __('One portal for technical professionals seeking work with WaveTeam, and for organisations requesting engineering services and verified technical manpower.') }}
            </p>
        </div>
    </section>

    {{-- Portal cards --}}
    <section id="portals" class="relative z-10 -mt-28 scroll-mt-8 sm:-mt-32">
        <div class="mx-auto grid max-w-7xl gap-6 px-4 sm:px-6 lg:grid-cols-2 lg:px-8">
            @php
                $portals = [
                    [
                        'tag' => __('For individuals'),
                        'title' => __('Applicant Portal'),
                        'text' => __('Build your professional profile, upload certificates, apply for vacancies across Sri Lanka and track every application in one place.'),
                        'icon' => 'heroicon-o-identification',
                        'accent' => 'bg-teal-400/15 text-teal-600',
                        'bar' => 'from-teal-400 to-teal-300',
                        'points' => [__('Apply for engineering & technical roles'), __('Secure document & police clearance storage'), __('Interview and assignment notifications')],
                        'register' => route('applicant.register'),
                        'login' => route('applicant.login'),
                        'cta' => __('Register as Applicant'),
                    ],
                    [
                        'tag' => __('For organisations'),
                        'title' => __('Service Request Portal'),
                        'text' => __('Request telecom, tower, power, fibre and manpower services. Receive quotations, approve them online and follow your project to completion.'),
                        'icon' => 'heroicon-o-building-office-2',
                        'accent' => 'bg-volt-400/15 text-volt-600',
                        'bar' => 'from-navy-600 to-volt-400',
                        'points' => [__('Submit detailed service requests'), __('Review and accept quotations online'), __('Track assigned teams and progress')],
                        'register' => route('organization.register'),
                        'login' => route('organization.login'),
                        'cta' => __('Register Organisation'),
                    ],
                ];
            @endphp

            @foreach ($portals as $p)
                <article class="group relative flex flex-col overflow-hidden rounded-2xl bg-white p-7 shadow-xl shadow-navy-950/10 ring-1 ring-slate-900/5 sm:p-9">
                    <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r {{ $p['bar'] }}"></div>
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">{{ $p['tag'] }}</p>
                            <h2 class="mt-2 text-2xl font-bold tracking-tight text-navy-900">{{ $p['title'] }}</h2>
                        </div>
                        <span class="grid h-12 w-12 shrink-0 place-items-center rounded-xl {{ $p['accent'] }}">
                            @svg($p['icon'], 'h-6 w-6')
                        </span>
                    </div>
                    <p class="mt-4 leading-relaxed text-slate-600">{{ $p['text'] }}</p>
                    <ul class="mt-6 space-y-2.5 text-sm text-slate-700">
                        @foreach ($p['points'] as $point)
                            <li class="flex gap-2.5">
                                @svg('heroicon-s-check-circle', 'mt-0.5 h-4 w-4 shrink-0 text-volt-500')
                                {{ $point }}
                            </li>
                        @endforeach
                    </ul>
                    <div class="mt-8 flex flex-wrap gap-3 pt-2 sm:mt-auto">
                        <a href="{{ $p['register'] }}" class="btn-primary">{{ $p['cta'] }} @svg('heroicon-m-arrow-right', 'h-4 w-4')</a>
                        <a href="{{ $p['login'] }}" class="btn-outline">{{ __('Sign in') }}</a>
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    {{-- Services --}}
    <section class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8">
        <div class="max-w-2xl">
            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-teal-600">{{ __('What we deliver') }}</p>
            <h2 class="mt-2 text-3xl font-bold tracking-tight text-navy-900">{{ __('Engineering services across the island') }}</h2>
        </div>
        @php
            $services = [
                ['heroicon-o-signal', __('Telecommunication Engineering')],
                ['heroicon-o-building-library', __('Tower Construction & Maintenance')],
                ['heroicon-o-arrows-right-left', __('Transmission Services')],
                ['heroicon-o-bolt', __('Electrical & Power Solutions')],
                ['heroicon-o-cog-6-tooth', __('Generator Services')],
                ['heroicon-o-shield-check', __('Lightning Protection & Earthing')],
                ['heroicon-o-globe-alt', __('Networking & Fibre Optics')],
                ['heroicon-o-truck', __('Crew & Equipment Transportation')],
                ['heroicon-o-user-group', __('Technical Manpower Services')],
            ];
        @endphp
        <div class="mt-10 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($services as [$icon, $label])
                <div class="flex items-center gap-4 rounded-xl border border-slate-200 bg-white px-5 py-4">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-navy-50 text-navy-600">@svg($icon, 'h-5 w-5')</span>
                    <span class="font-medium text-slate-800">{{ $label }}</span>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Trust / compliance --}}
    <section class="border-y border-slate-200 bg-white">
        <div class="mx-auto grid max-w-7xl gap-10 px-4 py-16 sm:px-6 md:grid-cols-3 lg:px-8">
            @foreach ([
                ['heroicon-o-lock-closed', __('Your data is protected'), __('Documents are encrypted and stored privately. Only authorised WaveTeam staff can view sensitive records.')],
                ['heroicon-o-document-check', __('Verified workforce'), __('Every technician’s qualifications and annual police clearance are checked before deployment.')],
                ['heroicon-o-bell-alert', __('Always up to date'), __('Automatic reminders keep certificates current, so verified people stay ready for work.')],
            ] as [$icon, $heading, $text])
                <div>
                    <span class="grid h-11 w-11 place-items-center rounded-xl bg-volt-400/15 text-volt-600">@svg($icon, 'h-6 w-6')</span>
                    <h3 class="mt-4 font-semibold text-navy-900">{{ $heading }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ $text }}</p>
                </div>
            @endforeach
        </div>
    </section>
</x-layouts.public>
