<x-layouts.portal :title="$vacancy->title" :portal="\App\Enums\Portal::Applicant">
    <a href="{{ route('applicant.vacancies.index') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-navy-700">@svg('heroicon-m-arrow-left', 'h-4 w-4') {{ __('All vacancies') }}</a>

    <div class="mt-4 grid gap-6 lg:grid-cols-[1fr_22rem]">
        <article class="rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
            <p class="text-xs font-semibold uppercase tracking-wider text-teal-600">{{ $vacancy->jobCategory->name }}</p>
            <h1 class="mt-1 text-2xl font-bold tracking-tight text-navy-900">{{ $vacancy->title }}</h1>

            <dl class="mt-6 grid gap-4 border-y border-slate-100 py-5 text-sm sm:grid-cols-2">
                <div><dt class="text-slate-500">{{ __('Location') }}</dt><dd class="mt-0.5 font-medium text-slate-800">{{ $vacancy->location_text ?: ($vacancy->district?->localizedName() ?? '—') }}@if ($vacancy->districts->isNotEmpty()) <span class="font-normal text-slate-500">({{ $vacancy->districts->map->localizedName()->implode(', ') }})</span>@endif</dd></div>
                <div><dt class="text-slate-500">{{ __('Employment type') }}</dt><dd class="mt-0.5 font-medium text-slate-800">{{ $vacancy->employment_type->getLabel() }}</dd></div>
                <div><dt class="text-slate-500">{{ __('Positions') }}</dt><dd class="mt-0.5 font-medium text-slate-800">{{ $vacancy->positions }}</dd></div>
                <div><dt class="text-slate-500">{{ __('Minimum experience') }}</dt><dd class="mt-0.5 font-medium text-slate-800">{{ (float) $vacancy->min_experience_years > 0 ? trans_choice(':count year|:count years', (float) $vacancy->min_experience_years) : __('Not specified') }}</dd></div>
                @if ($vacancy->closing_date)
                    <div><dt class="text-slate-500">{{ __('Closing date') }}</dt><dd class="mt-0.5 font-medium text-slate-800">{{ $vacancy->closing_date->format('j F Y') }}</dd></div>
                @endif
                <div><dt class="text-slate-500">{{ __('Police clearance') }}</dt><dd class="mt-0.5 font-medium text-slate-800">{{ $vacancy->requires_police_clearance ? __('Required') : __('Not required') }}</dd></div>
            </dl>

            {{-- Description is plain text authored by staff; escaped and line breaks preserved. --}}
            <div class="mt-6 whitespace-pre-line leading-relaxed text-slate-700">{{ $vacancy->description }}</div>

            @if ($vacancy->requirements)
                <h2 class="mt-8 font-semibold text-navy-900">{{ __('Requirements') }}</h2>
                <div class="mt-2 whitespace-pre-line leading-relaxed text-slate-700">{{ $vacancy->requirements }}</div>
            @endif
        </article>

        <aside class="space-y-4">
            @if ($application)
                <div class="rounded-2xl border border-volt-200 bg-volt-50 p-5">
                    <p class="font-semibold text-volt-700">{{ __('You applied on :d', ['d' => $application->submitted_at->format('j M Y')]) }}</p>
                    <p class="mt-2 text-sm">{{ __('Status:') }} <x-status-badge :status="$application->status" /></p>
                    <a href="{{ route('applicant.applications.index') }}" class="mt-4 inline-block text-sm font-medium text-navy-600 hover:text-navy-800">{{ __('Track your applications') }} →</a>
                </div>
            @elseif (! $isOpen)
                <div class="rounded-2xl border border-slate-200 bg-white p-5 text-sm text-slate-600">{{ __('This vacancy is closed.') }}</div>
            @else
                <form method="POST" action="{{ route('applicant.vacancies.apply', $vacancy) }}" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5" novalidate>
                    @csrf
                    <h2 class="font-semibold text-navy-900">{{ __('Apply for this role') }}</h2>

                    @error('vacancy')<p class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ $message }}</p>@enderror
                    @error('cv')<p class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ $message }}</p>@enderror

                    @unless ($hasCv)
                        <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">
                            {{ __('You need to upload your CV before applying.') }}
                            <a href="{{ route('applicant.documents.index') }}" class="font-semibold underline">{{ __('Upload CV') }}</a>
                        </p>
                    @endunless

                    @if ($vacancy->requires_police_clearance && ! in_array($pccStatus, [\App\Enums\PoliceClearanceStatus::Valid, \App\Enums\PoliceClearanceStatus::ExpiringSoon, \App\Enums\PoliceClearanceStatus::PendingVerification], true))
                        <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">
                            {{ __('This role requires a valid police clearance certificate. You can apply now, but please upload your certificate.') }}
                            <a href="{{ route('applicant.police-clearance.index') }}" class="font-semibold underline">{{ __('Upload') }}</a>
                        </p>
                    @endif

                    <x-form.textarea name="cover_note" :label="__('Message to WaveTeam (optional)')" rows="4" maxlength="2000" />
                    <x-form.checkbox name="confirm_accurate">{{ __('I confirm my profile and documents are accurate and up to date.') }}</x-form.checkbox>
                    <button type="submit" class="btn-primary w-full" @disabled(! $hasCv)>{{ __('Submit application') }}</button>
                </form>
            @endif
        </aside>
    </div>
</x-layouts.portal>
