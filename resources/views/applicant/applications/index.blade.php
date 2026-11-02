<x-layouts.portal :title="__('My applications')" :portal="\App\Enums\Portal::Applicant">
    <h1 class="text-2xl font-bold tracking-tight text-navy-900">{{ __('My applications') }}</h1>

    <div class="mt-6 space-y-4">
        @forelse ($applications as $application)
            <article class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-wider text-teal-600">{{ $application->vacancy->jobCategory->name }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-navy-900">
                            <a href="{{ route('applicant.vacancies.show', $application->vacancy) }}" class="hover:text-navy-700">{{ $application->vacancy->title }}</a>
                        </h2>
                        <p class="mt-0.5 text-sm text-slate-500">{{ __('Applied :d', ['d' => $application->submitted_at->format('j M Y')]) }}</p>
                    </div>
                    <x-status-badge :status="$application->status" class="!text-sm" />
                </div>

                @foreach ($application->interviews->where('status', \App\Enums\InterviewStatus::Scheduled) as $interview)
                    <div class="mt-4 flex gap-3 rounded-xl bg-navy-50 px-4 py-3 text-sm text-navy-900">
                        @svg('heroicon-o-calendar-days', 'h-5 w-5 shrink-0 text-navy-600')
                        <div>
                            <p class="font-semibold">{{ __('Interview: :d', ['d' => $interview->scheduled_at->format('l, j F Y \a\t g:i A')]) }}</p>
                            <p class="text-navy-700">{{ $interview->mode->getLabel() }}@if ($interview->location_or_link) · {{ $interview->location_or_link }}@endif</p>
                        </div>
                    </div>
                @endforeach

                {{-- Progress timeline --}}
                <ol class="mt-5 flex flex-wrap gap-x-2 gap-y-2 text-xs">
                    @foreach ($application->statusHistory as $i => $step)
                        @php $status = \App\Enums\ApplicationStatus::tryFrom($step->to_status); @endphp
                        <li class="flex items-center gap-2">
                            @if ($i > 0)<span class="h-px w-4 bg-slate-300" aria-hidden="true"></span>@endif
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-700" title="{{ $step->created_at->format('j M Y H:i') }}">
                                {{ $status?->getLabel() ?? $step->to_status }} · {{ $step->created_at->format('j M') }}
                            </span>
                        </li>
                    @endforeach
                </ol>
            </article>
        @empty
            <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center">
                @svg('heroicon-o-clipboard-document-check', 'mx-auto h-10 w-10 text-slate-300')
                <p class="mt-3 font-medium text-slate-700">{{ __('You have not applied for any vacancies yet.') }}</p>
                <a href="{{ route('applicant.vacancies.index') }}" class="btn-primary mt-4">{{ __('Browse vacancies') }}</a>
            </div>
        @endforelse
    </div>
</x-layouts.portal>
