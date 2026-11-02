<x-layouts.portal :title="__('New service request')" :portal="\App\Enums\Portal::Organization">
    <h1 class="text-2xl font-bold tracking-tight text-navy-900">{{ __('New service request') }}</h1>
    <p class="mt-1 text-slate-600">{{ __('Describe the work you need. The more detail you give, the faster and more accurate our quotation.') }}</p>

    @if ($organization->status === \App\Enums\OrganizationStatus::Pending)
        <p class="mt-4 flex gap-2 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">@svg('heroicon-o-information-circle', 'h-5 w-5 shrink-0') {{ __('Your organisation is still being reviewed. You can submit now — we will review your organisation together with this request.') }}</p>
    @elseif ($organization->status === \App\Enums\OrganizationStatus::Suspended)
        <p class="mt-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ __('Your organisation account is suspended. Please contact WaveTeam.') }}</p>
    @endif

    @error('organization')<p class="mt-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $message }}</p>@enderror

    <form method="POST" action="{{ route('organization.requests.store') }}" enctype="multipart/form-data" class="mt-8 space-y-6" novalidate>
        @csrf

        {{-- 1. Service --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <h2 class="flex items-center gap-3 font-semibold text-navy-900"><span class="grid h-7 w-7 place-items-center rounded-full bg-navy-600 text-xs text-white">1</span>{{ __('Service') }}</h2>
            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <x-form.select name="service_category_id" :label="__('Service category')" :options="$categories" :placeholder="__('Select…')" required />
                <x-form.input name="title" :label="__('Project title')" :hint="__('e.g. Antenna swap at 12 sites – Western Province')" required />
                <x-form.textarea class="sm:col-span-2" name="description" :label="__('Project description')" rows="5" required
                    :placeholder="__('Scope of work, sites, equipment involved, deliverables…')" />
            </div>
        </section>

        {{-- 2. Team --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <h2 class="flex items-center gap-3 font-semibold text-navy-900"><span class="grid h-7 w-7 place-items-center rounded-full bg-navy-600 text-xs text-white">2</span>{{ __('Team required') }}</h2>
            <div class="mt-5 grid gap-5 sm:grid-cols-3">
                <x-form.input name="required_headcount" type="number" min="1" max="500" :label="__('Number of technicians / employees')" value="1" required />
                <x-form.input name="required_experience_years" type="number" min="0" max="40" step="0.5" :label="__('Minimum experience (years)')" />
                <div class="sm:col-span-3">
                    <x-form.textarea name="required_qualifications" :label="__('Required qualifications')" rows="2" :placeholder="__('e.g. NVQ Level 4, certified tower climbers, CEB licence…')" />
                </div>
            </div>

            @php $selectedSkills = array_map('intval', (array) old('skills', [])); @endphp
            <details class="group mt-5 rounded-xl border border-slate-200" @if ($selectedSkills) open @endif>
                <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 text-sm font-medium text-slate-800">
                    {{ __('Specific skills needed (optional)') }}
                    @svg('heroicon-m-chevron-down', 'h-4 w-4 text-slate-400 transition group-open:rotate-180')
                </summary>
                <div class="grid gap-5 border-t border-slate-100 p-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($skills as $group => $list)
                        <fieldset>
                            <legend class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $group }}</legend>
                            <div class="mt-2 space-y-1.5">
                                @foreach ($list as $skill)
                                    <label class="flex items-center gap-2 text-sm text-slate-700">
                                        <input type="checkbox" name="skills[]" value="{{ $skill->id }}" class="h-4 w-4 rounded border-slate-300 text-navy-600 focus:ring-navy-500" @checked(in_array($skill->id, $selectedSkills, true))>
                                        {{ $skill->name }}
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                    @endforeach
                </div>
            </details>
        </section>

        {{-- 3. Location & schedule --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <h2 class="flex items-center gap-3 font-semibold text-navy-900"><span class="grid h-7 w-7 place-items-center rounded-full bg-navy-600 text-xs text-white">3</span>{{ __('Location & schedule') }}</h2>
            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <x-form.select name="district_id" :label="__('District')" :options="$districts" :placeholder="__('Select…')" required />
                <x-form.input name="location_text" :label="__('Project location / sites')" :hint="__('Town, site IDs or address')" required />
                <x-form.input name="start_date" type="date" :label="__('Preferred start date')" :min="now()->format('Y-m-d')" />
                <div>
                    <span class="block text-sm font-medium text-slate-700">{{ __('Expected duration') }}</span>
                    <div class="mt-1.5 flex gap-2">
                        <input name="expected_duration_value" type="number" min="1" max="999" value="{{ old('expected_duration_value') }}" aria-label="{{ __('Duration') }}"
                            class="w-24 rounded-lg border-slate-300 px-3.5 py-2.5 text-sm focus:border-navy-500 focus:ring-navy-500">
                        <select name="expected_duration_unit" aria-label="{{ __('Unit') }}" class="flex-1 rounded-lg border-slate-300 py-2.5 text-sm focus:border-navy-500 focus:ring-navy-500">
                            @foreach (['days' => __('days'), 'weeks' => __('weeks'), 'months' => __('months')] as $v => $l)
                                <option value="{{ $v }}" @selected(old('expected_duration_unit', 'weeks') === $v)>{{ $l }}</option>
                            @endforeach
                        </select>
                    </div>
                    @error('expected_duration_value')<p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>@enderror
                </div>
                <x-form.input name="working_hours" :label="__('Working hours')" :hint="__('e.g. 8:00–17:00, Mon–Sat; night work possible')" />
            </div>
        </section>

        {{-- 4. Requirements, budget, attachments --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <h2 class="flex items-center gap-3 font-semibold text-navy-900"><span class="grid h-7 w-7 place-items-center rounded-full bg-navy-600 text-xs text-white">4</span>{{ __('Requirements & attachments') }}</h2>
            <fieldset class="mt-5">
                <legend class="text-sm font-medium text-slate-700">{{ __('Do personnel need a valid police clearance for this site?') }}</legend>
                <div class="mt-2 flex flex-wrap gap-3">
                    @foreach (['1' => __('Yes (recommended)'), '0' => __('No')] as $v => $l)
                        <label class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm has-[:checked]:border-navy-500 has-[:checked]:bg-navy-50">
                            <input type="radio" name="requires_police_clearance" value="{{ $v }}" class="text-navy-600 focus:ring-navy-500" @checked((string) old('requires_police_clearance', '1') === (string) $v)> {{ $l }}
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <x-form.textarea class="sm:col-span-2" name="special_requirements" :label="__('Special requirements')" rows="3"
                    :placeholder="__('Safety requirements, site access, equipment or vehicles needed…')" />
                <x-form.input name="budget_amount" type="number" min="0" step="0.01" :label="__('Budget in LKR (optional)')" />
                <div>
                    @php $attError = $errors->first('attachments') ?: collect($errors->getMessages())->filter(fn ($v, $k) => str_starts_with($k, 'attachments.'))->flatten()->first(); @endphp
                    <label for="attachments" class="block text-sm font-medium text-slate-700">{{ __('Attachments (optional)') }}</label>
                    <input id="attachments" name="attachments[]" type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx"
                        class="mt-1.5 block w-full cursor-pointer rounded-lg border border-dashed border-slate-300 bg-slate-50 text-sm text-slate-600 file:mr-4 file:cursor-pointer file:border-0 file:bg-navy-600 file:px-4 file:py-2.5 file:text-sm file:font-semibold file:text-white">
                    <p class="mt-1.5 text-xs text-slate-500">{{ __('Up to :n files: drawings, BOQ, site list. PDF, images, Word or Excel; :mb MB each.', ['n' => $maxFiles, 'mb' => $maxMb]) }}</p>
                    @if ($attError)<p class="mt-1.5 text-sm text-rose-600">{{ $attError }}</p>@endif
                </div>
            </div>
        </section>

        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" class="btn-primary !px-8 !py-3" @disabled($organization->status === \App\Enums\OrganizationStatus::Suspended)>{{ __('Submit request') }}</button>
            <a href="{{ route('organization.requests.index') }}" class="btn-outline">{{ __('Cancel') }}</a>
        </div>
    </form>
</x-layouts.portal>
