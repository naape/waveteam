@props(['status'])

{{-- Renders any HasLabel/HasColor enum as a consistent pill. --}}
@php
    $tone = match ($status->getColor()) {
        'success' => 'bg-volt-50 text-volt-700 ring-volt-200',
        'warning' => 'bg-amber-50 text-amber-800 ring-amber-200',
        'danger' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'info' => 'bg-teal-50 text-teal-700 ring-teal-200',
        'primary' => 'bg-navy-50 text-navy-700 ring-navy-200',
        default => 'bg-slate-100 text-slate-700 ring-slate-200',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset {$tone}"]) }}>
    {{ $status->getLabel() }}
</span>
