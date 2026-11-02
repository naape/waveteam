@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
    'hint' => null,
    'bag' => 'default',
    'required' => false,
])

@php
    $id = $attributes->get('id', $name);
    $error = $errors->getBag($bag)->first($name);
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
@endphp

<div {{ $attributes->only('class')->merge(['class' => '']) }}>
    <label for="{{ $id }}" class="block text-sm font-medium text-slate-700">
        {{ $label }}
        @if ($required)<span class="text-rose-600" aria-hidden="true">*</span>@endif
    </label>
    <input
        id="{{ $id }}"
        name="{{ $name }}"
        type="{{ $type }}"
        @if ($type !== 'password') value="{{ old($name, $value) }}" @endif
        @if ($required) required @endif
        @if ($error) aria-invalid="true" @endif
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->except(['class', 'id'])->merge([
            'class' => 'mt-1.5 block w-full rounded-lg border-slate-300 bg-white px-3.5 py-2.5 text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-navy-500 focus:ring-navy-500 sm:text-sm '
                .($error ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500' : ''),
        ]) }}
    >
    @if ($hint)
        <p id="{{ $id }}-hint" class="mt-1.5 text-xs text-slate-500">{{ $hint }}</p>
    @endif
    @if ($error)
        <p id="{{ $id }}-error" class="mt-1.5 text-sm text-rose-600">{{ $error }}</p>
    @endif
</div>
