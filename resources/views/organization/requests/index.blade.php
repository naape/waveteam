<x-layouts.portal :title="__('Service requests')" :portal="\App\Enums\Portal::Organization">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="text-2xl font-bold tracking-tight text-navy-900">{{ __('Service requests') }}</h1>
        <a href="{{ route('organization.requests.create') }}" class="btn-primary">@svg('heroicon-m-plus', 'h-4 w-4') {{ __('New request') }}</a>
    </div>

    <nav class="mt-6 flex gap-2 overflow-x-auto" aria-label="{{ __('Filter') }}">
        @foreach (['' => __('All'), 'open' => __('Open'), 'completed' => __('Completed'), 'cancelled' => __('Cancelled')] as $key => $label)
            <a href="{{ route('organization.requests.index', array_filter(['status' => $key])) }}" @if ((string) $filter === (string) $key) aria-current="page" @endif
               class="whitespace-nowrap rounded-full px-4 py-1.5 text-sm font-medium {{ (string) $filter === (string) $key ? 'bg-navy-600 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50' }}">{{ $label }}</a>
        @endforeach
    </nav>

    <div class="mt-4 overflow-hidden rounded-2xl border border-slate-200 bg-white">
        @if ($requests->isEmpty())
            <div class="p-10 text-center">
                @svg('heroicon-o-clipboard-document-list', 'mx-auto h-10 w-10 text-slate-300')
                <p class="mt-3 font-medium text-slate-700">{{ __('No service requests here yet.') }}</p>
                <a href="{{ route('organization.requests.create') }}" class="btn-primary mt-4">{{ __('Submit your first request') }}</a>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-slate-100 bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-5 py-3 font-medium">{{ __('Reference') }}</th>
                            <th class="px-5 py-3 font-medium">{{ __('Project') }}</th>
                            <th class="px-5 py-3 font-medium">{{ __('Submitted') }}</th>
                            <th class="px-5 py-3 font-medium">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($requests as $req)
                            <tr class="hover:bg-slate-50">
                                <td class="whitespace-nowrap px-5 py-3.5 font-mono text-xs"><a href="{{ route('organization.requests.show', $req) }}" class="font-semibold text-navy-600 hover:text-navy-800">{{ $req->reference }}</a></td>
                                <td class="px-5 py-3.5"><a href="{{ route('organization.requests.show', $req) }}" class="font-medium text-slate-900 hover:text-navy-700">{{ $req->title }}</a><span class="block text-xs text-slate-500">{{ $req->serviceCategory->name }}</span></td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-slate-500">{{ $req->created_at->format('j M Y') }}</td>
                                <td class="px-5 py-3.5"><x-status-badge :status="$req->status" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
    <div class="mt-6">{{ $requests->links() }}</div>
</x-layouts.portal>
