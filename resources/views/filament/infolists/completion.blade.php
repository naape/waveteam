@php($value = (int) $getState())
<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    <div class="flex items-center gap-3">
        <div class="h-2 flex-1 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10" role="progressbar" aria-valuenow="{{ $value }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ __('Profile completion') }}">
            <div class="h-full rounded-full {{ $value >= 80 ? 'bg-success-500' : ($value >= 50 ? 'bg-warning-500' : 'bg-danger-500') }}" style="width: {{ $value }}%"></div>
        </div>
        <span class="text-sm font-medium text-gray-950 dark:text-white">{{ $value }}%</span>
    </div>
</x-dynamic-component>
