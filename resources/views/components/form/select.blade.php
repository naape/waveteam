@props(['name', 'label', 'options' => [], 'value' => null, 'placeholder' => null, 'required' => false])

@php $error = $errors->first($name); @endphp

<div {{ $attributes->only('class') }}>
    <label for="{{ $name }}" class="block text-sm font-medium text-slate-700">
        {{ $label }}
        @if ($required)<span class="text-rose-600" aria-hidden="true">*</span>@endif
    </label>
    <select id="{{ $name }}" name="{{ $name }}" @if ($required) required @endif @if ($error) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif
        class="mt-1.5 block w-full rounded-lg border-slate-300 bg-white px-3.5 py-2.5 text-slate-900 shadow-sm focus:border-navy-500 focus:ring-navy-500 sm:text-sm {{ $error ? 'border-rose-400' : '' }}">
        @if ($placeholder)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach ($options as $key => $text)
            <option value="{{ $key }}" @selected((string) old($name, $value) === (string) $key)>{{ $text }}</option>
        @endforeach
    </select>
    @if ($error)
        <p id="{{ $name }}-error" class="mt-1.5 text-sm text-rose-600">{{ $error }}</p>
    @endif
</div>
