@props(['name' => 'file', 'label', 'hint' => null, 'accept' => '.pdf,.jpg,.jpeg,.png', 'required' => false])

@php $error = $errors->first($name); @endphp

<div {{ $attributes->only('class') }}>
    <label for="{{ $name }}" class="block text-sm font-medium text-slate-700">
        {{ $label }} @if ($required)<span class="text-rose-600" aria-hidden="true">*</span>@endif
    </label>
    <input id="{{ $name }}" name="{{ $name }}" type="file" accept="{{ $accept }}" @if ($required) required @endif
        @if ($error) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif
        class="mt-1.5 block w-full cursor-pointer rounded-lg border border-dashed border-slate-300 bg-slate-50 text-sm text-slate-600 file:mr-4 file:cursor-pointer file:border-0 file:bg-navy-600 file:px-4 file:py-2.5 file:text-sm file:font-semibold file:text-white hover:file:bg-navy-700 {{ $error ? 'border-rose-400' : '' }}">
    @if ($hint)
        <p class="mt-1.5 text-xs text-slate-500">{{ $hint }}</p>
    @endif
    @if ($error)
        <p id="{{ $name }}-error" class="mt-1.5 text-sm text-rose-600">{{ $error }}</p>
    @endif
</div>
