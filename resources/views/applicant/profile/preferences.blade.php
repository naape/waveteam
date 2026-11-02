<x-applicant.profile-shell current="preferences" :profile="$profile" :title="__('Work preferences & availability')"
    :intro="__('Where you are willing to work and when you are available.')">

    <form method="POST" action="{{ route('applicant.profile.preferences') }}" class="space-y-6" novalidate>
        @csrf
        @method('PUT')

        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <h2 class="font-semibold text-navy-900">{{ __('Availability') }}</h2>
            @if ($profile->availability_status === \App\Enums\AvailabilityStatus::Engaged)
                <p class="mt-3 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ __('You are currently engaged on a WaveTeam project. Your availability will be updated when the assignment ends.') }}</p>
                <input type="hidden" name="availability_status" value="available">
            @else
                @php $current = old('availability_status', $profile->availability_status->value); @endphp
                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    @foreach (['available' => [__('Available now'), __('Ready to start work immediately')], 'available_from' => [__('Available from a date'), __('Choose your start date below')], 'unavailable' => [__('Not available'), __('Not looking for work right now')]] as $value => [$label, $desc])
                        <label class="cursor-pointer rounded-xl border border-slate-200 p-4 has-[:checked]:border-navy-500 has-[:checked]:bg-navy-50">
                            <input type="radio" name="availability_status" value="{{ $value }}" class="text-navy-600 focus:ring-navy-500" @checked($current === $value)>
                            <span class="ml-2 text-sm font-semibold text-slate-800">{{ $label }}</span>
                            <span class="mt-1 block text-xs text-slate-500">{{ $desc }}</span>
                        </label>
                    @endforeach
                </div>
                <x-form.input class="mt-4 max-w-xs" name="available_from" type="date" :label="__('Available from')" :value="$profile->available_from?->format('Y-m-d')" />
            @endif
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <h2 class="font-semibold text-navy-900">{{ __('Preferred working locations') }} <span class="text-rose-600" aria-hidden="true">*</span></h2>
            <p class="mt-1 text-sm text-slate-500">{{ __('Select every district where you are willing to work.') }}</p>
            @error('districts')<p class="mt-2 text-sm text-rose-600">{{ $message }}</p>@enderror
            @php $sel = array_map('intval', (array) old('districts', $selected)); @endphp
            <div class="mt-4 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($districts as $province => $list)
                    <fieldset>
                        <legend class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __(':p Province', ['p' => $province]) }}</legend>
                        <div class="mt-2 space-y-1.5">
                            @foreach ($list as $district)
                                <label class="flex items-center gap-2 text-sm text-slate-700">
                                    <input type="checkbox" name="districts[]" value="{{ $district->id }}" class="h-4 w-4 rounded border-slate-300 text-navy-600 focus:ring-navy-500" @checked(in_array($district->id, $sel, true))>
                                    {{ $district->localizedName() }}
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach
            </div>
        </section>

        <div class="flex flex-wrap gap-3">
            <button type="submit" class="btn-primary">{{ __('Save preferences') }}</button>
            <a href="{{ route('applicant.documents.index') }}" class="btn-outline">{{ __('Next: upload documents') }} @svg('heroicon-m-arrow-right', 'h-4 w-4')</a>
        </div>
    </form>
</x-applicant.profile-shell>
