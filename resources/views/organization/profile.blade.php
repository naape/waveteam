<x-layouts.portal :title="__('Organisation')" :portal="\App\Enums\Portal::Organization">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-navy-900">{{ __('Organisation profile') }}</h1>
            <p class="mt-1 text-slate-600">{{ __('Keep your organisation and contact details up to date.') }}</p>
        </div>
        <x-status-badge :status="$organization->status" class="!px-3 !py-1 !text-sm" />
    </div>

    <div class="mt-8 grid gap-6 lg:grid-cols-[1fr_22rem]">
        <form method="POST" action="{{ route('organization.profile') }}" class="space-y-6" novalidate>
            @csrf
            @method('PUT')

            <section class="rounded-2xl border border-slate-200 bg-white p-6">
                <h2 class="font-semibold text-navy-900">{{ __('Organisation') }}</h2>
                @if ($identityLocked)
                    <p class="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">{{ __('Your organisation has been verified. To change its name, type or registration number, please message WaveTeam.') }}</p>
                @endif
                <div class="mt-4 grid gap-5 sm:grid-cols-2">
                    @if ($identityLocked)
                        <div class="sm:col-span-2"><span class="text-sm font-medium text-slate-700">{{ __('Organisation name') }}</span><p class="mt-1 text-slate-900">{{ $organization->name }}</p></div>
                        <div><span class="text-sm font-medium text-slate-700">{{ __('Type') }}</span><p class="mt-1 text-slate-900">{{ $organization->organization_type->getLabel() }}</p></div>
                        <div><span class="text-sm font-medium text-slate-700">{{ __('Registration no.') }}</span><p class="mt-1 text-slate-900">{{ $organization->registration_number ?: '—' }}</p></div>
                    @else
                        <x-form.input class="sm:col-span-2" name="organization_name" :label="__('Organisation name')" :value="$organization->name" required />
                        <x-form.select name="organization_type" :label="__('Organisation type')" :options="$organizationTypes" :value="$organization->organization_type->value" required />
                        <x-form.input name="registration_number" :label="__('Business registration no. (optional)')" :value="$organization->registration_number" />
                    @endif
                    <x-form.textarea class="sm:col-span-2" name="address" :label="__('Address')" :value="$organization->address" rows="2" required />
                    <x-form.textarea class="sm:col-span-2" name="billing_address" :label="__('Billing address')" :value="$organization->billing_address" rows="2" />
                    <x-form.input name="phone" type="tel" :label="__('Organisation phone')" :value="$organization->phone" required />
                    <div><span class="text-sm font-medium text-slate-700">{{ __('Organisation email') }}</span><p class="mt-2 text-slate-900">{{ $organization->email }}</p></div>
                </div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-6">
                <h2 class="font-semibold text-navy-900">{{ __('Your contact details') }}</h2>
                <div class="mt-4 grid gap-5 sm:grid-cols-2">
                    <x-form.input name="contact_name" :label="__('Full name')" :value="auth()->user()->name" required />
                    <x-form.input name="designation" :label="__('Designation')" :value="$membership->designation" required />
                    <x-form.input name="contact_phone" type="tel" :label="__('Direct phone (optional)')" :value="$membership->phone" />
                    <div><span class="text-sm font-medium text-slate-700">{{ __('Sign-in email') }}</span><p class="mt-2 text-slate-900">{{ auth()->user()->email }}</p></div>
                </div>
            </section>

            <button type="submit" class="btn-primary">{{ __('Save changes') }}</button>
        </form>

        <aside class="space-y-4">
            <section class="rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="font-semibold text-navy-900">{{ __('Supporting documents') }}</h2>
                <p class="mt-1 text-xs text-slate-500">{{ __('e.g. business registration certificate, letter of authorisation.') }}</p>
                <ul class="mt-4 divide-y divide-slate-100">
                    @forelse ($organization->documents as $doc)
                        <li class="flex items-center justify-between gap-3 py-2.5 text-sm">
                            <span class="min-w-0 truncate text-slate-800">{{ $doc->title }}</span>
                            <a href="{{ route('organization.files.show', $doc->file) }}" target="_blank" rel="noopener" class="shrink-0 font-medium text-navy-600 hover:text-navy-800">{{ __('View') }}</a>
                        </li>
                    @empty
                        <li class="py-2 text-sm text-slate-500">{{ __('No documents uploaded.') }}</li>
                    @endforelse
                </ul>
                <form method="POST" action="{{ route('organization.profile.documents.store') }}" enctype="multipart/form-data" class="mt-4 space-y-3 border-t border-slate-100 pt-4" novalidate>
                    @csrf
                    <x-form.input name="title" :label="__('Document name')" required />
                    <x-form.file name="file" :label="__('File')" accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx" :hint="__('PDF, image, Word or Excel, up to :mb MB.', ['mb' => $maxMb])" required />
                    <button type="submit" class="btn-outline w-full">@svg('heroicon-o-arrow-up-tray', 'h-4 w-4') {{ __('Upload') }}</button>
                </form>
            </section>
        </aside>
    </div>
</x-layouts.portal>
