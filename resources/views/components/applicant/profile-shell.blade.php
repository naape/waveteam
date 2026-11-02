@props(['current', 'title', 'intro' => null, 'profile'])

@php
    $steps = \App\Support\Profile\ProfileSections::steps();
    $doneKeys = collect(\App\Support\Profile\ProfileCompletion::checklist($profile))->filter(fn ($c) => $c['done'])->keys()->all();
@endphp

<x-layouts.portal :title="$title" :portal="\App\Enums\Portal::Applicant">
    <div class="grid gap-8 lg:grid-cols-[15rem_1fr]">
        {{-- Wizard steps --}}
        <aside>
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Profile') }}</p>
                <p class="mt-1 text-2xl font-bold text-navy-900">{{ $profile->profile_completion }}%</p>
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100">
                    <div class="h-full rounded-full bg-gradient-to-r from-teal-400 to-volt-400" style="width: {{ $profile->profile_completion }}%"></div>
                </div>
            </div>
            <nav class="mt-4 flex gap-1 overflow-x-auto pb-2 lg:flex-col lg:overflow-visible lg:pb-0" aria-label="{{ __('Profile sections') }}">
                @foreach ($steps as $i => $step)
                    @php
                        $active = $step['key'] === $current;
                        $done = in_array($step['key'], $doneKeys, true) || ($step['key'] === 'certifications' && $profile->certifications()->exists()) || ($step['key'] === 'driving-licences' && $profile->drivingLicences()->exists()) || ($step['key'] === 'military' && $profile->militaryServices()->exists());
                    @endphp
                    <a href="{{ route($step['route'], $step['params']) }}" @if ($active) aria-current="step" @endif
                       class="flex shrink-0 items-center gap-3 rounded-lg px-3 py-2 text-sm {{ $active ? 'bg-navy-600 font-semibold text-white' : 'text-slate-600 hover:bg-white hover:text-navy-700' }}">
                        <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full text-xs font-bold {{ $active ? 'bg-white/20' : ($done ? 'bg-volt-100 text-volt-700' : 'bg-slate-200 text-slate-600') }}">
                            @if ($done && ! $active) @svg('heroicon-m-check', 'h-3.5 w-3.5') @else {{ $i + 1 }} @endif
                        </span>
                        {{ $step['label'] }}
                    </a>
                @endforeach
            </nav>
        </aside>

        <div class="min-w-0">
            <h1 class="text-2xl font-bold tracking-tight text-navy-900">{{ $title }}</h1>
            @if ($intro)
                <p class="mt-1 text-slate-600">{{ $intro }}</p>
            @endif
            <div class="mt-6">
                {{ $slot }}
            </div>
        </div>
    </div>
</x-layouts.portal>
