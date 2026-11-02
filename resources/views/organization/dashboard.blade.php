<x-layouts.portal :title="__('Dashboard')" :portal="\App\Enums\Portal::Organization">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-sm text-slate-500">{{ $membership->designation }} · {{ auth()->user()->name }}</p>
            <h1 class="text-2xl font-bold tracking-tight text-navy-900">{{ $organization->name }}</h1>
        </div>
        <a href="{{ route('organization.requests.create') }}" class="btn-primary">@svg('heroicon-m-plus', 'h-4 w-4') {{ __('New service request') }}</a>
    </div>

    @if ($organization->status === \App\Enums\OrganizationStatus::Pending)
        <div class="mt-6 flex gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status">
            @svg('heroicon-o-clock', 'h-5 w-5 shrink-0 text-amber-500')
            <p>{{ __('Your organisation is awaiting review by WaveTeam. You can already submit service requests — we will review your organisation together with your first request.') }}</p>
        </div>
    @elseif ($organization->status === \App\Enums\OrganizationStatus::Suspended)
        <div class="mt-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">{{ __('Your organisation account is suspended. Please contact WaveTeam.') }}</div>
    @endif

    <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            [__('Open requests'), $stats['open'], 'heroicon-o-clipboard-document-list', route('organization.requests.index', ['status' => 'open'])],
            [__('Quotations awaiting you'), $stats['quotations'], 'heroicon-o-document-currency-dollar', route('organization.requests.index', ['status' => 'open'])],
            [__('Teams on site'), $stats['active'], 'heroicon-o-user-group', route('organization.requests.index', ['status' => 'open'])],
            [__('Completed projects'), $stats['completed'], 'heroicon-o-check-badge', route('organization.requests.index', ['status' => 'completed'])],
        ] as [$label, $count, $icon, $href])
            <a href="{{ $href }}" class="rounded-2xl border bg-white p-5 transition hover:border-navy-300 {{ $label === __('Quotations awaiting you') && $count ? 'border-navy-400 ring-2 ring-navy-100' : 'border-slate-200' }}">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-medium text-slate-500">{{ $label }}</h2>
                    <span class="text-slate-400">@svg($icon, 'h-5 w-5')</span>
                </div>
                <p class="mt-2 text-3xl font-bold text-navy-900">{{ $count }}</p>
            </a>
        @endforeach
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <section class="rounded-2xl border border-slate-200 bg-white p-6 lg:col-span-2">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-navy-900">{{ __('Recent requests') }}</h2>
                <a href="{{ route('organization.requests.index') }}" class="text-sm font-medium text-navy-600 hover:text-navy-800">{{ __('View all') }}</a>
            </div>
            <ul class="mt-3 divide-y divide-slate-100">
                @forelse ($recent as $req)
                    <li>
                        <a href="{{ route('organization.requests.show', $req) }}" class="flex items-center justify-between gap-3 py-3 hover:bg-slate-50">
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium text-slate-900">{{ $req->title }}</span>
                                <span class="block text-xs text-slate-500"><span class="font-mono">{{ $req->reference }}</span> · {{ $req->serviceCategory->name }}</span>
                            </span>
                            <x-status-badge :status="$req->status" />
                        </a>
                    </li>
                @empty
                    <li class="py-6 text-center text-sm text-slate-500">
                        {{ __('No service requests yet.') }}
                        <a href="{{ route('organization.requests.create') }}" class="font-semibold text-navy-600">{{ __('Submit your first request') }}</a>
                    </li>
                @endforelse
            </ul>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-navy-900">{{ __('Notifications') }} @if ($unread)<span class="ml-1 rounded-full bg-navy-600 px-2 py-0.5 text-xs text-white">{{ $unread }}</span>@endif</h2>
                @if ($unread)
                    <form method="POST" action="{{ route('organization.notifications.read-all') }}">@csrf<button class="text-xs font-medium text-slate-500 hover:text-navy-700">{{ __('Mark all read') }}</button></form>
                @endif
            </div>

            @if ($unreadMessages->isNotEmpty())
                <ul class="mt-3 space-y-2">
                    @foreach ($unreadMessages as $m)
                        <li><a href="{{ route('organization.requests.show', $m->serviceRequest) }}#messages" class="flex gap-2 rounded-lg bg-teal-50 px-3 py-2 text-sm text-teal-900 hover:bg-teal-100">@svg('heroicon-o-chat-bubble-left-ellipsis', 'h-4 w-4 shrink-0 mt-0.5') <span>{{ __('New message on :ref', ['ref' => $m->serviceRequest->reference]) }}</span></a></li>
                    @endforeach
                </ul>
            @endif

            <ul class="mt-3 divide-y divide-slate-100">
                @forelse ($notifications as $n)
                    <li>
                        <form method="POST" action="{{ route('organization.notifications.open', $n->id) }}">
                            @csrf
                            <button type="submit" class="flex w-full items-start gap-3 py-3 text-left">
                                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $n->read_at ? 'bg-transparent' : 'bg-teal-500' }}"></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm {{ $n->read_at ? 'text-slate-600' : 'font-semibold text-slate-900' }}">{{ $n->data['title'] ?? '' }}</span>
                                    <span class="block text-xs text-slate-400">{{ $n->created_at->diffForHumans() }}</span>
                                </span>
                            </button>
                        </form>
                    </li>
                @empty
                    <li class="py-4 text-sm text-slate-500">{{ __('No notifications yet.') }}</li>
                @endforelse
            </ul>
        </section>
    </div>
</x-layouts.portal>
