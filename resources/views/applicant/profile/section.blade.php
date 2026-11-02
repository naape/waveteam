{{-- Generic view for every repeatable profile section (see ProfileSections). --}}
<x-applicant.profile-shell :current="$section" :profile="$profile" :title="$definition['title']" :intro="$definition['intro']">

    <section id="entries" class="space-y-3">
        @forelse ($entries as $entry)
            @php [$headline, $detail] = ($definition['summary'])($entry); @endphp
            <div class="flex items-start justify-between gap-4 rounded-xl border border-slate-200 bg-white p-4 {{ $editing?->is($entry) ? 'ring-2 ring-navy-500' : '' }}">
                <div class="min-w-0">
                    <p class="font-medium text-slate-900">{{ $headline }}</p>
                    @if ($detail)<p class="mt-0.5 text-sm text-slate-500">{{ $detail }}</p>@endif
                </div>
                <div class="flex shrink-0 items-center gap-1">
                    <a href="{{ route('applicant.profile.section', ['section' => $section, 'edit' => $entry->id]) }}#entry-form" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-navy-700" title="{{ __('Edit') }}">
                        @svg('heroicon-o-pencil-square', 'h-5 w-5')<span class="sr-only">{{ __('Edit') }}</span>
                    </a>
                    <form method="POST" action="{{ route('applicant.profile.section.destroy', [$section, $entry->id]) }}" onsubmit="return confirm('{{ __('Remove this entry?') }}')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="rounded-lg p-2 text-slate-500 hover:bg-rose-50 hover:text-rose-600" title="{{ __('Remove') }}">
                            @svg('heroicon-o-trash', 'h-5 w-5')<span class="sr-only">{{ __('Remove') }}</span>
                        </button>
                    </form>
                </div>
            </div>
        @empty
            <p class="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-500">
                {{ ($definition['optional'] ?? false) ? __('Nothing added — this section is optional.') : __('Nothing added yet.') }}
            </p>
        @endforelse
    </section>

    <form id="entry-form" method="POST" novalidate
          action="{{ $editing ? route('applicant.profile.section.update', [$section, $editing->id]) : route('applicant.profile.section.store', $section) }}"
          class="mt-6 rounded-2xl border border-slate-200 bg-white p-6">
        @csrf
        @if ($editing) @method('PUT') @endif

        <h2 class="font-semibold text-navy-900">{{ $editing ? __('Edit entry') : $definition['add_label'] }}</h2>

        <div class="mt-4 grid gap-5 sm:grid-cols-2">
            @foreach ($definition['fields'] as $name => $field)
                @php
                    $value = $editing?->{$name};
                    if ($value instanceof \Carbon\CarbonInterface) { $value = $value->format('Y-m-d'); }
                    $span = ($field['span'] ?? 1) === 2 ? 'sm:col-span-2' : '';
                    $required = in_array('required', $field['rules'], true);
                @endphp

                @switch($field['type'])
                    @case('select')
                        <x-form.select :class="$span" :name="$name" :label="$field['label']" :options="$field['options']" :value="$value" :placeholder="__('Select…')" :required="$required" />
                        @break
                    @case('textarea')
                        <x-form.textarea :class="$span" :name="$name" :label="$field['label']" :value="$value" :required="$required" />
                        @break
                    @case('checkbox')
                        <div class="{{ $span }} flex items-end">
                            <input type="hidden" name="{{ $name }}" value="0">
                            <x-form.checkbox :name="$name" :checked="(bool) $value">{{ $field['label'] }}</x-form.checkbox>
                        </div>
                        @break
                    @case('checkboxes')
                        @php $selected = old($name, $value ?? []); @endphp
                        <fieldset class="{{ $span }}">
                            <legend class="text-sm font-medium text-slate-700">{{ $field['label'] }}</legend>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach ($field['options'] as $optValue => $optLabel)
                                    <label class="cursor-pointer">
                                        <input type="checkbox" name="{{ $name }}[]" value="{{ $optValue }}" class="peer sr-only" @checked(in_array($optValue, (array) $selected, true))>
                                        <span class="inline-block rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-600 peer-checked:border-navy-600 peer-checked:bg-navy-600 peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-teal-400">{{ $optLabel }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @error($name)<p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>@enderror
                        </fieldset>
                        @break
                    @default
                        <x-form.input :class="$span" :name="$name" :type="$field['type']" :label="$field['label']" :value="$value" :hint="$field['hint'] ?? null" :required="$required" autocomplete="off" />
                @endswitch
            @endforeach
        </div>

        <div class="mt-6 flex flex-wrap gap-3">
            <button type="submit" class="btn-primary">{{ $editing ? __('Save changes') : $definition['add_label'] }}</button>
            @if ($editing)
                <a href="{{ route('applicant.profile.section', $section) }}" class="btn-outline">{{ __('Cancel') }}</a>
            @endif
        </div>
    </form>

    @if ($next = \App\Support\Profile\ProfileSections::nextStep($section))
        <div class="mt-6 flex justify-end">
            <a href="{{ route($next['route'], $next['params']) }}" class="btn-outline">{{ __('Next: :step', ['step' => $next['label']]) }} @svg('heroicon-m-arrow-right', 'h-4 w-4')</a>
        </div>
    @endif
</x-applicant.profile-shell>
