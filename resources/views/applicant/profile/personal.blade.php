<x-applicant.profile-shell current="personal" :profile="$profile" :title="__('Personal details')"
    :intro="__('These details are used to verify your identity and contact you about work.')">

    <form method="POST" action="{{ route('applicant.profile.personal') }}" enctype="multipart/form-data" class="space-y-6" novalidate>
        @csrf
        @method('PUT')

        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex flex-col gap-6 sm:flex-row sm:items-start">
                <div class="shrink-0">
                    @if ($profile->photo_file_id && ($photo = $profile->documents()->where('document_type', 'photo')->where('is_current', true)->first()))
                        <img src="{{ route('documents.file', $photo) }}" alt="{{ __('Profile photo') }}" class="h-28 w-28 rounded-2xl object-cover ring-1 ring-slate-200">
                    @else
                        <span class="grid h-28 w-28 place-items-center rounded-2xl bg-slate-100 text-slate-400">@svg('heroicon-o-user', 'h-12 w-12')</span>
                    @endif
                </div>
                <div class="grid flex-1 gap-5 sm:grid-cols-2">
                    <x-form.input class="sm:col-span-2" name="full_name" :label="__('Full name (as on NIC)')" :value="$profile->full_name" required />
                    <div>
                        <span class="block text-sm font-medium text-slate-700">{{ __('NIC number') }}</span>
                        <p class="mt-1.5 rounded-lg border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-700">{{ $profile->nic_number }}</p>
                        <p class="mt-1.5 text-xs text-slate-500">{{ __('To correct your NIC, contact WaveTeam.') }}</p>
                    </div>
                    <x-form.file name="photo" :label="__('Profile photo')" accept=".jpg,.jpeg,.png" :hint="__('A clear, recent passport-style photo (JPG or PNG).')" />
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <h2 class="font-semibold text-navy-900">{{ __('Identity') }}</h2>
            <div class="mt-4 grid gap-5 sm:grid-cols-3">
                <x-form.input name="date_of_birth" type="date" :label="__('Date of birth')" :value="$profile->date_of_birth?->format('Y-m-d')" required />
                <x-form.select name="gender" :label="__('Gender (optional)')" :value="$profile->gender" :placeholder="__('Prefer not to say')"
                    :options="['male' => __('Male'), 'female' => __('Female'), 'other' => __('Other')]" />
                <x-form.input name="passport_number" :label="__('Passport number (optional)')" :value="$profile->passport_number" autocomplete="off" />
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <h2 class="font-semibold text-navy-900">{{ __('Address') }}</h2>
            <div class="mt-4 grid gap-5 sm:grid-cols-2">
                <x-form.textarea class="sm:col-span-2" name="permanent_address" :label="__('Permanent address')" :value="$profile->permanent_address" rows="2" required />
                <div class="sm:col-span-2">
                    <input type="hidden" name="current_same" value="0">
                    <x-form.checkbox name="current_same" :checked="$profile->current_address === null || $profile->current_address === $profile->permanent_address">{{ __('My current address is the same') }}</x-form.checkbox>
                    <x-form.textarea class="mt-3" name="current_address" :label="__('Current address')" :value="$profile->current_address !== $profile->permanent_address ? $profile->current_address : null" rows="2" />
                </div>
                <x-form.select name="district_id" :label="__('District')" :options="$districts" :value="$profile->district_id" :placeholder="__('Select district…')" required />
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <h2 class="font-semibold text-navy-900">{{ __('Contact') }}</h2>
            <div class="mt-4 grid gap-5 sm:grid-cols-2">
                <x-form.input name="contact_number" type="tel" :label="__('Mobile number')" :value="$profile->contact_number" required />
                <div>
                    <x-form.input name="whatsapp_number" type="tel" :label="__('WhatsApp number')" :value="$profile->whatsapp_number !== $profile->contact_number ? $profile->whatsapp_number : null" />
                    <input type="hidden" name="whatsapp_same" value="0">
                    <x-form.checkbox class="mt-2" name="whatsapp_same" :checked="$profile->whatsapp_number === null || $profile->whatsapp_number === $profile->contact_number">{{ __('Same as mobile number') }}</x-form.checkbox>
                </div>
                <div>
                    <span class="block text-sm font-medium text-slate-700">{{ __('Email address') }}</span>
                    <p class="mt-1.5 rounded-lg border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-700">{{ auth()->user()->email }}</p>
                </div>
            </div>
            <h3 class="mt-8 text-sm font-semibold text-navy-900">{{ __('Emergency contact') }}</h3>
            <div class="mt-3 grid gap-5 sm:grid-cols-2">
                <x-form.input name="emergency_contact_name" :label="__('Contact person')" :value="$profile->emergency_contact_name" required />
                <x-form.input name="emergency_contact_number" type="tel" :label="__('Contact number')" :value="$profile->emergency_contact_number" required />
            </div>
        </section>

        <div class="flex flex-wrap gap-3">
            <button type="submit" name="continue" value="1" class="btn-primary">{{ __('Save & continue') }} @svg('heroicon-m-arrow-right', 'h-4 w-4')</button>
            <button type="submit" class="btn-outline">{{ __('Save') }}</button>
        </div>
    </form>
</x-applicant.profile-shell>
