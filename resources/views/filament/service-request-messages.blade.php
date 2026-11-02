@php
    /** @var \App\Models\ServiceRequest $record */
    $messages = $getRecord()->messages()->with(['sender', 'files'])->orderBy('created_at')->get();
@endphp
<div class="space-y-3">
    @forelse ($messages as $m)
        @php $staff = $m->sender?->isStaff(); @endphp
        <div @class([
            'rounded-xl px-4 py-3 text-sm',
            'bg-amber-50 ring-1 ring-amber-200 dark:bg-amber-500/10' => $m->is_internal,
            'bg-primary-50 dark:bg-primary-500/10' => $staff && ! $m->is_internal,
            'bg-gray-100 dark:bg-white/5' => ! $staff,
        ])>
            <div class="flex flex-wrap items-center gap-2 text-xs font-semibold text-gray-600 dark:text-gray-300">
                <span>{{ $m->sender?->name ?? __('System') }}</span>
                <span class="font-normal text-gray-400">{{ $staff ? __('WaveTeam') : __('Client') }} · {{ $m->created_at->format('j M Y, H:i') }}</span>
                @if ($m->is_internal)
                    <x-filament::badge color="warning" size="sm">{{ __('Internal note') }}</x-filament::badge>
                @endif
            </div>
            <p class="mt-1 whitespace-pre-line text-gray-800 dark:text-gray-100">{{ $m->body }}</p>
            @foreach ($m->files as $f)
                <a href="{{ route('files.show', [$f, 'download' => 1]) }}" class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-primary-600 underline">📎 {{ $f->original_name }}</a>
            @endforeach
        </div>
    @empty
        <p class="text-sm text-gray-500">{{ __('No messages yet.') }}</p>
    @endforelse
</div>
