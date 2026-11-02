@php
    /** @var \App\Models\ServiceRequest $r */
    $r = $this->record;
    [$start, $end] = $this->getPeriod();
    $team = $this->getTeam();
    $results = $this->getResults();
    $factors = ['skills' => __('Skills'), 'experience' => __('Experience'), 'qualifications' => __('Qualifications'), 'location' => __('Location'), 'completeness' => __('Profile')];
@endphp

<x-filament-panels::page>
    {{-- Request summary --}}
    <x-filament::section>
        <div class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-5">
            <div class="lg:col-span-2">
                <p class="text-gray-500">{{ $r->serviceCategory?->name }} · {{ $r->organization->name }}</p>
                <p class="font-semibold text-gray-950 dark:text-white">{{ $r->title }}</p>
            </div>
            <div><p class="text-gray-500">{{ __('Period') }}</p><p class="font-medium">{{ $start->format('j M Y') }} – {{ $end->format('j M Y') }}</p></div>
            <div><p class="text-gray-500">{{ __('Location') }}</p><p class="font-medium">{{ $r->district?->name_en ?? '—' }}</p></div>
            <div>
                <p class="text-gray-500">{{ __('Team') }}</p>
                <p class="font-medium">{{ __(':a of :n assigned', ['a' => $team->count(), 'n' => $r->required_headcount]) }}</p>
            </div>
        </div>
        <div class="mt-3 flex flex-wrap gap-2 text-xs">
            @if ($r->requires_police_clearance)
                <x-filament::badge color="warning">{{ __('Police clearance required until :d', ['d' => $end->format('j M Y')]) }}</x-filament::badge>
            @endif
            @if ((float) $r->required_experience_years > 0)
                <x-filament::badge color="gray">{{ __(':y+ years experience', ['y' => (float) $r->required_experience_years]) }}</x-filament::badge>
            @endif
            @foreach ($r->skills as $s)
                <x-filament::badge color="info">{{ $s->name }}@if ((float) $s->pivot->min_years > 0) ({{ (float) $s->pivot->min_years }}+ y)@endif</x-filament::badge>
            @endforeach
        </div>
        @unless ($this->canAssign())
            <p class="mt-3 text-sm text-warning-600">
                {{ in_array($r->status, \App\Actions\Workforce\AssignmentActions::ASSIGNABLE_REQUEST, true) ? __('You can view matches but not assign.') : __('People can be assigned once the request is Approved (current status: :s).', ['s' => $r->status->getLabel()]) }}
            </p>
        @endunless
    </x-filament::section>

    {{-- Current team --}}
    @if ($team->isNotEmpty())
        <x-filament::section :heading="__('Current team')" collapsible>
            <ul class="divide-y divide-gray-100 text-sm dark:divide-white/10">
                @foreach ($team as $a)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span><span class="font-medium">{{ $a->applicantProfile->full_name }}</span> · {{ $a->role_title }}</span>
                        <span class="flex items-center gap-2 text-gray-500">{{ $a->start_date->format('j M') }} – {{ $a->end_date?->format('j M Y') ?? '…' }} <x-filament::badge :color="$a->status->getColor()">{{ $a->status->getLabel() }}</x-filament::badge></span>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    {{-- Options --}}
    <div class="flex flex-wrap items-center gap-6 text-sm">
        <label class="flex items-center gap-2">
            <x-filament::input.checkbox wire:model.live="strictExperience" />
            {{ __('Must meet minimum experience') }}
        </label>
        <label class="flex items-center gap-2">
            <x-filament::input.checkbox wire:model.live="districtOnly" />
            {{ __('Only people living in / willing to work in :d', ['d' => $r->district?->name_en]) }}
        </label>
        <span class="text-gray-500">{{ trans_choice(':count candidate|:count candidates', $results->count()) }}</span>
    </div>

    {{-- Candidates --}}
    <div class="space-y-3">
        @forelse ($results as $i => $m)
            @php $p = $m->profile; @endphp
            <x-filament::section wire:key="candidate-{{ $p->id }}">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start">
                    <div class="flex items-center gap-4 lg:w-64">
                        <div class="grid h-14 w-14 shrink-0 place-items-center rounded-full text-lg font-bold text-white {{ $m->score >= 75 ? 'bg-success-600' : ($m->score >= 50 ? 'bg-primary-600' : 'bg-gray-400') }}">{{ $m->score }}</div>
                        <div class="min-w-0">
                            <a href="{{ \App\Filament\Resources\Applicants\ApplicantResource::getUrl('view', ['record' => $p->id]) }}" target="_blank" class="font-semibold text-primary-600 hover:underline">{{ $p->full_name }}</a>
                            <p class="text-xs text-gray-500">#{{ $i + 1 }} · {{ (float) $p->total_experience_years }} {{ __('yrs') }} · {{ $p->availability_status->getLabel() }}</p>
                        </div>
                    </div>

                    <div class="grid flex-1 gap-3 sm:grid-cols-5">
                        @foreach ($factors as $key => $label)
                            @php $v = (int) round(($m->breakdown[$key] ?? 0) * 100); @endphp
                            <div>
                                <div class="flex justify-between text-xs text-gray-500"><span>{{ $label }}</span><span>{{ $v }}%</span></div>
                                <div class="mt-1 h-1.5 rounded-full bg-gray-100 dark:bg-white/10"><div class="h-1.5 rounded-full bg-primary-500" style="width: {{ $v }}%"></div></div>
                            </div>
                        @endforeach
                        <ul class="space-y-0.5 text-xs text-gray-600 sm:col-span-5 dark:text-gray-300">
                            @foreach ($m->reasons as $reason)<li>• {{ $reason }}</li>@endforeach
                            @foreach ($m->warnings as $warning)<li class="text-warning-600">⚠ {{ $warning }}</li>@endforeach
                        </ul>
                    </div>

                    <div class="shrink-0">{{ ($this->assignAction)(['profile' => $p->id]) }}</div>
                </div>
            </x-filament::section>
        @empty
            <x-filament::section>
                <p class="text-sm text-gray-600">{{ __('No eligible candidates. Candidates must be verified, active, available for these dates, in a matching job category') }}@if ($r->requires_police_clearance){{ __(', and hold police clearance valid until :d', ['d' => $end->format('j M Y')]) }}@endif. {{ __('Try relaxing the options above.') }}</p>
            </x-filament::section>
        @endforelse
    </div>
</x-filament-panels::page>
