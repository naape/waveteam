@props(['name', 'checked' => false, 'value' => '1'])

@php $error = $errors->first($name); @endphp

<div {{ $attributes->only('class') }}>
    <label class="flex items-start gap-3 text-sm text-slate-700">
        <input type="checkbox" name="{{ $name }}" value="{{ $value }}" @checked(old($name, $checked))
            @if ($error) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif
            {{ $attributes->except('class') }}
            class="mt-0.5 h-4 w-4 shrink-0 rounded border-slate-300 text-navy-600 focus:ring-navy-500">
        <span>{{ $slot }}</span>
    </label>
    @if ($error)
        <p id="{{ $name }}-error" class="ml-7 mt-1 text-sm text-rose-600">{{ $error }}</p>
    @endif
</div>
