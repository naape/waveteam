<x-layouts.portal :title="__('Vacancies')" :portal="\App\Enums\Portal::Applicant">
    <h1 class="text-2xl font-bold tracking-tight text-navy-900">{{ __('Available vacancies') }}</h1>

    <form method="GET" action="{{ route('applicant.vacancies.index') }}" class="mt-6 grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-[1fr_14rem_14rem_auto]" role="search">
        <label class="sr-only" for="q">{{ __('Search') }}</label>
        <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" placeholder="{{ __('Search job title or location…') }}"
            class="rounded-lg border-slate-300 text-sm focus:border-navy-500 focus:ring-navy-500">
        <label class="sr-only" for="category">{{ __('Category') }}</label>
        <select id="category" name="category" class="rounded-lg border-slate-300 text-sm focus:border-navy-500 focus:ring-navy-500">
            <option value="">{{ __('All categories') }}</option>
            @foreach ($categories as $id => $name)
                <option value="{{ $id }}" @selected((int) ($filters['category'] ?? 0) === $id)>{{ $name }}</option>
            @endforeach
        </select>
        <label class="sr-only" for="district">{{ __('Location') }}</label>
        <select id="district" name="district" class="rounded-lg border-slate-300 text-sm focus:border-navy-500 focus:ring-navy-500">
            <option value="">{{ __('All locations') }}</option>
            @foreach ($districts as $id => $name)
                <option value="{{ $id }}" @selected((int) ($filters['district'] ?? 0) === $id)>{{ $name }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn-primary">@svg('heroicon-m-magnifying-glass', 'h-4 w-4') {{ __('Search') }}</button>
    </form>

    <p class="mt-6 text-sm text-slate-500">{{ trans_choice(':count vacancy found|:count vacancies found', $vacancies->total()) }}</p>

    <div class="mt-3 space-y-3">
        @forelse ($vacancies as $vacancy)
            <a href="{{ route('applicant.vacancies.show', $vacancy) }}" class="group block rounded-2xl border border-slate-200 bg-white p-5 transition hover:border-navy-300 hover:shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-wider text-teal-600">{{ $vacancy->jobCategory->name }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-navy-900 group-hover:text-navy-700">{{ $vacancy->title }}</h2>
                    </div>
                    @if (in_array($vacancy->id, $appliedIds, true))
                        <span class="rounded-full bg-volt-100 px-2.5 py-0.5 text-xs font-semibold text-volt-700">{{ __('Applied') }}</span>
                    @endif
                </div>
                <div class="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-sm text-slate-500">
                    <span class="inline-flex items-center gap-1.5">@svg('heroicon-o-map-pin', 'h-4 w-4') {{ $vacancy->location_text ?: ($vacancy->district?->localizedName() ?? __('Multiple locations')) }}</span>
                    <span class="inline-flex items-center gap-1.5">@svg('heroicon-o-clock', 'h-4 w-4') {{ $vacancy->employment_type->getLabel() }}</span>
                    <span class="inline-flex items-center gap-1.5">@svg('heroicon-o-user-group', 'h-4 w-4') {{ trans_choice(':count position|:count positions', $vacancy->positions) }}</span>
                    @if ($vacancy->requires_police_clearance)
                        <span class="inline-flex items-center gap-1.5">@svg('heroicon-o-shield-check', 'h-4 w-4') {{ __('Police clearance required') }}</span>
                    @endif
                    @if ($vacancy->closing_date)
                        <span class="inline-flex items-center gap-1.5">@svg('heroicon-o-calendar', 'h-4 w-4') {{ __('Closes :d', ['d' => $vacancy->closing_date->format('j M Y')]) }}</span>
                    @endif
                </div>
            </a>
        @empty
            <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center">
                @svg('heroicon-o-briefcase', 'mx-auto h-10 w-10 text-slate-300')
                <p class="mt-3 font-medium text-slate-700">{{ __('No vacancies match your search.') }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ __('Try another category or location, or check back soon.') }}</p>
            </div>
        @endforelse
    </div>

    <div class="mt-6">{{ $vacancies->links() }}</div>
</x-layouts.portal>
