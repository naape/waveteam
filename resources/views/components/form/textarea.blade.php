@props(['name', 'label', 'value' => null, 'rows' => 3, 'required' => false])

@php $error = $errors->first($name); @endphp

<div {{ $attributes->only('class') }}>
    <label for="{{ $name }}" class="block text-sm font-medium text-slate-700">
        {{ $label }}
        @if ($required)<span class="text-rose-600" aria-hidden="true">*</span>@endif
    </label>
    <textarea id="{{ $name }}" name="{{ $name }}" rows="{{ $rows }}" @if ($required) required @endif @if ($error) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif
        {{ $attributes->except('class')->merge(['class' => 'mt-1.5 block w-full rounded-lg border-slate-300 bg-white px-3.5 py-2.5 text-slate-900 shadow-sm focus:border-navy-500 focus:ring-navy-500 sm:text-sm '.($error ? 'border-rose-400' : '')]) }}>{{ old($name, $value) }}</textarea>
    @if ($error)
        <p id="{{ $name }}-error" class="mt-1.5 text-sm text-rose-600">{{ $error }}</p>
    @endif
</div>
