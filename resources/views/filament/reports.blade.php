<x-filament-panels::page>
    <div class="grid gap-6 lg:grid-cols-[16rem_1fr]">
        {{-- Report list --}}
        <nav class="space-y-1" aria-label="{{ __('Reports') }}">
            @foreach ($reports as $key => $r)
                <button type="button" wire:click="$set('reportKey', '{{ $key }}')"
                    @class([
                        'w-full rounded-lg px-3 py-2 text-left text-sm transition',
                        'bg-primary-600 font-semibold text-white' => $key === $report->key,
                        'text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/5' => $key !== $report->key,
                    ])>{{ $r->title }}</button>
            @endforeach
        </nav>

        <div class="min-w-0 space-y-6">
            <x-filament::section :heading="$report->title" :description="$report->description">
                {{-- Filters --}}
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    @if (in_array('from', $report->filters))
                        <label class="text-sm"><span class="text-gray-600 dark:text-gray-300">{{ __('From') }}</span>
                            <x-filament::input.wrapper class="mt-1"><x-filament::input type="date" wire:model.live="filters.from" /></x-filament::input.wrapper></label>
                        <label class="text-sm"><span class="text-gray-600 dark:text-gray-300">{{ __('To') }}</span>
                            <x-filament::input.wrapper class="mt-1"><x-filament::input type="date" wire:model.live="filters.to" /></x-filament::input.wrapper></label>
                    @endif
                    @if (in_array('days', $report->filters))
                        <label class="text-sm"><span class="text-gray-600 dark:text-gray-300">{{ __('Due within (days)') }}</span>
                            <x-filament::input.wrapper class="mt-1"><x-filament::input type="number" min="1" max="365" placeholder="60" wire:model.live.debounce.500ms="filters.days" /></x-filament::input.wrapper></label>
                    @endif
                    @if (in_array('job_category_id', $report->filters))
                        <label class="text-sm"><span class="text-gray-600 dark:text-gray-300">{{ __('Job category') }}</span>
                            <x-filament::input.wrapper class="mt-1"><x-filament::input.select wire:model.live="filters.job_category_id">
                                <option value="">{{ __('All') }}</option>
                                @foreach ($categories as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                            </x-filament::input.select></x-filament::input.wrapper></label>
                    @endif
                    @if (in_array('district_id', $report->filters))
                        <label class="text-sm"><span class="text-gray-600 dark:text-gray-300">{{ __('District') }}</span>
                            <x-filament::input.wrapper class="mt-1"><x-filament::input.select wire:model.live="filters.district_id">
                                <option value="">{{ __('All') }}</option>
                                @foreach ($districts as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                            </x-filament::input.select></x-filament::input.wrapper></label>
                    @endif
                    @if (in_array('status', $report->filters) && $statuses)
                        <label class="text-sm"><span class="text-gray-600 dark:text-gray-300">{{ __('Status') }}</span>
                            <x-filament::input.wrapper class="mt-1"><x-filament::input.select wire:model.live="filters.status">
                                <option value="">{{ __('All') }}</option>
                                @foreach ($statuses as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                            </x-filament::input.select></x-filament::input.wrapper></label>
                    @endif
                </div>

                {{-- Summary --}}
                @if ($summary)
                    <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($summary as $label => $value)
                            <div class="rounded-xl bg-gray-50 px-4 py-3 dark:bg-white/5">
                                <p class="text-xs text-gray-500">{{ $label }}</p>
                                <p class="text-xl font-bold text-gray-950 dark:text-white">{{ is_numeric($value) ? number_format($value) : $value }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- Export --}}
                <div class="mt-6 flex flex-wrap items-center gap-3">
                    <span class="text-sm text-gray-600 dark:text-gray-300">{{ trans_choice(':count row|:count rows', $count) }}</span>
                    @if ($canExport)
                        <x-filament::button icon="heroicon-o-table-cells" wire:click="export('xlsx')" wire:loading.attr="disabled">{{ __('Export Excel') }}</x-filament::button>
                        <x-filament::button icon="heroicon-o-document-arrow-down" color="gray" wire:click="export('pdf')" wire:loading.attr="disabled">{{ __('Export PDF') }}</x-filament::button>
                        <span wire:loading wire:target="export" class="text-sm text-gray-500">{{ __('Preparing…') }}</span>
                    @else
                        <span class="text-xs text-gray-500">{{ __('You can view reports but not export them.') }}</span>
                    @endif
                </div>
            </x-filament::section>

            {{-- Preview --}}
            <x-filament::section :heading="__('Preview')" :description="$count > count($preview) ? __('First :n of :t rows. Export for the full report.', ['n' => count($preview), 't' => number_format($count)]) : null">
                <div class="-mx-6 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-white/10">
                            <tr>@foreach ($report->columns as $heading)<th class="whitespace-nowrap px-4 py-2 font-medium first:pl-6">{{ $heading }}</th>@endforeach</tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @forelse ($preview as $row)
                                <tr>@foreach (array_keys($report->columns) as $col)<td class="whitespace-nowrap px-4 py-2 first:pl-6">{{ is_float($row[$col] ?? null) ? number_format($row[$col], $row[$col] == floor($row[$col]) ? 0 : 2) : ($row[$col] ?? '') }}</td>@endforeach</tr>
                            @empty
                                <tr><td colspan="{{ count($report->columns) }}" class="px-6 py-6 text-center text-gray-500">{{ __('No data for these filters.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
