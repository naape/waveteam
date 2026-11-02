<x-layouts.portal :title="__('Dashboard')" :portal="\App\Enums\Portal::Applicant">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-sm text-slate-500">{{ __('Welcome back,') }}</p>
            <h1 class="text-2xl font-bold tracking-tight text-navy-900">{{ $profile->full_name }}</h1>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <span class="rounded-full bg-white px-3 py-1.5 ring-1 ring-slate-200">{{ __('Profile:') }} <x-status-badge :status="$profile->verification_status" /></span>
            <span class="rounded-full bg-white px-3 py-1.5 ring-1 ring-slate-200">{{ __('Availability:') }} <strong class="font-semibold text-slate-800">{{ $profile->availability_status->getLabel() }}</strong></span>
        </div>
    </div>

    <div class="mt-8 grid gap-6 lg:grid-cols-3">
        {{-- Profile completion --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-6 lg:row-span-2">
            <h2 class="text-sm font-medium text-slate-500">{{ __('Profile completion') }}</h2>
            <p class="mt-2 text-4xl font-bold text-navy-900">{{ $profile->profile_completion }}%</p>
            <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-valuenow="{{ $profile->profile_completion }}" aria-valuemin="0" aria-valuemax="100">
                <div class="h-full rounded-full bg-gradient-to-r from-teal-400 to-volt-400" style="width: {{ $profile->profile_completion }}%"></div>
            </div>
            <ul class="mt-5 space-y-2 text-sm">
                @foreach ($checklist as $key => $item)
                    <li>
                        @php $params = $item['route'] === 'applicant.profile.section' ? ['section' => $key] : []; @endphp
                        <a href="{{ route($item['route'], $params) }}" class="flex items-center gap-2.5 {{ $item['done'] ? 'text-slate-500' : 'text-slate-800 hover:text-navy-700' }}">
                            @if ($item['done'])
                                @svg('heroicon-s-check-circle', 'h-5 w-5 shrink-0 text-volt-500')
                            @else
                                <span class="h-5 w-5 shrink-0 rounded-full border-2 border-slate-300"></span>
                            @endif
                            {{ $item['label'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>

        {{-- Police clearance --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-medium text-slate-500">{{ __('Police clearance') }}</h2>
                @svg('heroicon-o-shield-check', 'h-5 w-5 text-slate-400')
            </div>
            <div class="mt-3"><x-status-badge :status="$pccStatus" class="!px-3 !py-1 !text-sm" /></div>
            <p class="mt-3 text-sm text-slate-600">
                @if ($profile->currentPoliceClearance?->renewalDate())
                    {{ __('Next renewal: :date', ['date' => $profile->currentPoliceClearance->renewalDate()->format('j M Y')]) }}
                @elseif ($pendingPcc)
                    {{ __('Uploaded :d — awaiting verification.', ['d' => $pendingPcc->uploaded_at->format('j M Y')]) }}
                @else
                    {{ __('Upload your certificate to become eligible for site assignments.') }}
                @endif
            </p>
            <a href="{{ route('applicant.police-clearance.index') }}" class="mt-4 inline-block text-sm font-semibold text-navy-600 hover:text-navy-800">{{ __('Manage') }} →</a>
        </section>

        {{-- Documents --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-medium text-slate-500">{{ __('Documents') }}</h2>
                @svg('heroicon-o-folder', 'h-5 w-5 text-slate-400')
            </div>
            <ul class="mt-3 space-y-1.5 text-sm">
                <li class="flex justify-between"><span>{{ __('CV / résumé') }}</span><span class="{{ $documents['cv'] ? 'text-volt-600' : 'text-slate-400' }}">{{ $documents['cv'] ? __('Uploaded') : __('Missing') }}</span></li>
                <li class="flex justify-between"><span>{{ __('NIC copy') }}</span><span class="{{ $documents['nic'] ? 'text-volt-600' : 'text-slate-400' }}">{{ $documents['nic'] ? __('Uploaded') : __('Missing') }}</span></li>
                <li class="flex justify-between"><span>{{ __('Certificates') }}</span><span class="text-slate-600">{{ $documents['certificates'] }}</span></li>
            </ul>
            <a href="{{ route('applicant.documents.index') }}" class="mt-4 inline-block text-sm font-semibold text-navy-600 hover:text-navy-800">{{ __('Manage') }} →</a>
        </section>

        {{-- Assignments --}}
        @if ($assignments->isNotEmpty())
            <section class="rounded-2xl border border-navy-200 bg-navy-50/60 p-6 lg:col-span-2">
                <h2 class="font-semibold text-navy-900">{{ __('My assignments') }}</h2>
                <ul class="mt-3 space-y-3">
                    @foreach ($assignments as $a)
                        <li class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-white p-4 ring-1 ring-navy-100">
                            <div class="min-w-0">
                                <p class="font-semibold text-slate-900">{{ $a->role_title }} · {{ $a->serviceRequest->title }}</p>
                                <p class="text-sm text-slate-500">
                                    {{ $a->serviceRequest->location_text }} ·
                                    {{ $a->start_date->format('j M Y') }}@if ($a->end_date) – {{ $a->end_date->format('j M Y') }}@endif
                                </p>
                            </div>
                            <x-status-badge :status="$a->status" class="!text-sm" />
                        </li>
                    @endforeach
                </ul>
                <p class="mt-3 text-xs text-slate-500">{{ __('WaveTeam will contact you with site details. Keep your police clearance valid for the whole assignment.') }}</p>
            </section>
        @endif

        {{-- Notifications --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-6 lg:col-span-2">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-navy-900">{{ __('Notifications') }} @if ($unread)<span class="ml-1 rounded-full bg-navy-600 px-2 py-0.5 text-xs text-white">{{ $unread }}</span>@endif</h2>
                @if ($unread)
                    <form method="POST" action="{{ route('applicant.notifications.read-all') }}">@csrf<button class="text-xs font-medium text-slate-500 hover:text-navy-700">{{ __('Mark all read') }}</button></form>
                @endif
            </div>
            <ul class="mt-3 divide-y divide-slate-100">
                @forelse ($notifications as $n)
                    <li>
                        <form method="POST" action="{{ route('applicant.notifications.open', $n->id) }}">
                            @csrf
                            <button type="submit" class="flex w-full items-start gap-3 py-3 text-left">
                                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $n->read_at ? 'bg-transparent' : 'bg-teal-500' }}"></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm {{ $n->read_at ? 'text-slate-600' : 'font-semibold text-slate-900' }}">{{ $n->data['title'] ?? '' }}</span>
                                    <span class="mt-0.5 block truncate text-xs text-slate-500">{{ $n->data['body'] ?? '' }}</span>
                                </span>
                                <span class="shrink-0 text-xs text-slate-400">{{ $n->created_at->diffForHumans(short: true) }}</span>
                            </button>
                        </form>
                    </li>
                @empty
                    <li class="py-4 text-sm text-slate-500">{{ __('No notifications yet. Interview invitations and assignments will appear here.') }}</li>
                @endforelse
            </ul>
        </section>

        {{-- Applications --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-6 lg:col-span-2">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-navy-900">{{ __('My applications') }}</h2>
                <a href="{{ route('applicant.applications.index') }}" class="text-sm font-medium text-navy-600 hover:text-navy-800">{{ __('View all') }}</a>
            </div>
            <ul class="mt-3 divide-y divide-slate-100">
                @forelse ($applications as $application)
                    <li class="flex items-center justify-between gap-3 py-3 text-sm">
                        <span class="min-w-0 truncate text-slate-800">{{ $application->vacancy->title }}</span>
                        <x-status-badge :status="$application->status" />
                    </li>
                @empty
                    <li class="py-4 text-sm text-slate-500">{{ __('No applications yet.') }}</li>
                @endforelse
            </ul>
        </section>

        {{-- Suggested vacancies --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-navy-900">{{ __('Vacancies for you') }}</h2>
                <a href="{{ route('applicant.vacancies.index') }}" class="text-sm font-medium text-navy-600 hover:text-navy-800">{{ __('All') }}</a>
            </div>
            <ul class="mt-3 space-y-3">
                @forelse ($vacancies as $vacancy)
                    <li>
                        <a href="{{ route('applicant.vacancies.show', $vacancy) }}" class="block rounded-lg border border-slate-100 p-3 hover:border-navy-200 hover:bg-navy-50/40">
                            <span class="block text-sm font-medium text-slate-900">{{ $vacancy->title }}</span>
                            <span class="block text-xs text-slate-500">{{ $vacancy->location_text ?: $vacancy->district?->localizedName() }}</span>
                        </a>
                    </li>
                @empty
                    <li class="text-sm text-slate-500">{{ __('No matching vacancies right now.') }}</li>
                @endforelse
            </ul>
        </section>
    </div>
</x-layouts.portal>
