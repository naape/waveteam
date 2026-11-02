<x-applicant.profile-shell current="skills" :profile="$profile" :title="__('Skills & experience')"
    :intro="__('Tell us what kind of work you do. WaveTeam uses this to match you with vacancies and client projects.')">

    <form method="POST" action="{{ route('applicant.profile.skills') }}" class="space-y-6" novalidate>
        @csrf
        @method('PUT')

        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <h2 class="font-semibold text-navy-900">{{ __('Job categories') }} <span class="text-rose-600" aria-hidden="true">*</span></h2>
            <p class="mt-1 text-sm text-slate-500">{{ __('Select every category you are qualified to work in.') }}</p>
            @php $selCats = old('job_categories', $selectedCategories); @endphp
            <div class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($categories as $category)
                    <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-slate-200 px-3 py-2.5 text-sm hover:bg-slate-50 has-[:checked]:border-navy-500 has-[:checked]:bg-navy-50">
                        <input type="checkbox" name="job_categories[]" value="{{ $category->id }}" class="h-4 w-4 rounded border-slate-300 text-navy-600 focus:ring-navy-500" @checked(in_array($category->id, array_map('intval', (array) $selCats), true))>
                        {{ $category->name }}
                    </label>
                @endforeach
            </div>
            @error('job_categories')<p class="mt-2 text-sm text-rose-600">{{ $message }}</p>@enderror

            <x-form.input class="mt-6 max-w-xs" name="total_experience_years" type="number" step="0.5" min="0" max="60"
                :label="__('Total years of work experience')" :value="$profile->total_experience_years" required />
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <h2 class="font-semibold text-navy-900">{{ __('Technical skills') }}</h2>
            <p class="mt-1 text-sm text-slate-500">{{ __('Tick your skills and add years of experience and level for each.') }}</p>

            <div class="mt-4 space-y-3">
                @foreach ($categories as $category)
                    @continue($category->skills->isEmpty())
                    @php $count = $category->skills->filter(fn ($s) => $selectedSkills->has($s->id))->count(); @endphp
                    <details class="group rounded-xl border border-slate-200" @if ($count) open @endif>
                        <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 text-sm font-medium text-slate-800">
                            <span>{{ $category->name }} @if ($count)<span class="ml-2 rounded-full bg-volt-100 px-2 py-0.5 text-xs text-volt-700">{{ $count }}</span>@endif</span>
                            @svg('heroicon-m-chevron-down', 'h-4 w-4 text-slate-400 transition group-open:rotate-180')
                        </summary>
                        <div class="divide-y divide-slate-100 border-t border-slate-100">
                            @foreach ($category->skills as $skill)
                                @php
                                    $pivot = $selectedSkills->get($skill->id)?->pivot;
                                    $oldSkill = old("skills.{$skill->id}");
                                @endphp
                                <div class="grid items-center gap-3 px-4 py-2.5 sm:grid-cols-[1fr_7rem_10rem]">
                                    <label class="flex items-center gap-3 text-sm text-slate-700">
                                        <input type="hidden" name="skills[{{ $skill->id }}][selected]" value="0">
                                        <input type="checkbox" name="skills[{{ $skill->id }}][selected]" value="1" class="h-4 w-4 rounded border-slate-300 text-navy-600 focus:ring-navy-500"
                                            @checked($oldSkill ? ! empty($oldSkill['selected']) : $pivot !== null)>
                                        {{ $skill->name }}
                                    </label>
                                    <label class="flex items-center gap-2 text-xs text-slate-500">
                                        <input type="number" name="skills[{{ $skill->id }}][years]" min="0" max="60" step="0.5" value="{{ $oldSkill['years'] ?? $pivot?->years ?? '' }}"
                                            class="w-16 rounded-md border-slate-300 py-1.5 text-sm focus:border-navy-500 focus:ring-navy-500" aria-label="{{ __('Years') }}">
                                        {{ __('yrs') }}
                                    </label>
                                    <select name="skills[{{ $skill->id }}][level]" aria-label="{{ __('Level') }}" class="rounded-md border-slate-300 py-1.5 text-sm focus:border-navy-500 focus:ring-navy-500">
                                        @foreach (['basic' => __('Basic'), 'intermediate' => __('Intermediate'), 'expert' => __('Expert')] as $lv => $lvLabel)
                                            <option value="{{ $lv }}" @selected(($oldSkill['level'] ?? $pivot?->level ?? 'intermediate') === $lv)>{{ $lvLabel }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endforeach
            </div>
        </section>

        <div class="flex flex-wrap gap-3">
            <button type="submit" name="continue" value="1" class="btn-primary">{{ __('Save & continue') }} @svg('heroicon-m-arrow-right', 'h-4 w-4')</button>
            <button type="submit" class="btn-outline">{{ __('Save') }}</button>
        </div>
    </form>
</x-applicant.profile-shell>
