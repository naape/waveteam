@if (session('status'))
    <div {{ $attributes->merge(['class' => 'flex gap-3 rounded-lg border border-volt-200 bg-volt-50 px-4 py-3 text-sm text-volt-700']) }} role="status">
        @svg('heroicon-s-check-circle', 'h-5 w-5 shrink-0 text-volt-500')
        <p>{{ session('status') }}</p>
    </div>
@endif
