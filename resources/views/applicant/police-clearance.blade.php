<x-layouts.portal :title="__('Police clearance')" :portal="\App\Enums\Portal::Applicant">
    <h1 class="text-2xl font-bold tracking-tight text-navy-900">{{ __('Police clearance certificate') }}</h1>
    <p class="mt-1 max-w-3xl text-slate-600">{{ __('Most client sites require a valid police clearance. Upload a new certificate every year; we will remind you before your renewal date.') }}</p>

    <div class="mt-8 grid gap-6 lg:grid-cols-[1fr_24rem]">
        <div class="space-y-6">
            {{-- Current status --}}
            <section class="rounded-2xl border border-slate-200 bg-white p-6">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <p class="text-sm text-slate-500">{{ __('Current status') }}</p>
                        <x-status-badge :status="$status" class="mt-2 !px-3 !py-1 !text-sm" />
                    </div>
                    @if ($current?->renewalDate())
                        <div class="text-right">
                            <p class="text-sm text-slate-500">{{ __('Next renewal due') }}</p>
                            <p class="mt-1 text-lg font-semibold text-navy-900">{{ $current->renewalDate()->format('j F Y') }}</p>
                            <p class="text-xs text-slate-500">{{ $current->renewalDate()->isPast() ? __('Overdue') : __(':n days left', ['n' => (int) today()->diffInDays($current->renewalDate())]) }}</p>
                        </div>
                    @endif
                </div>
                <p class="mt-4 text-sm text-slate-600">
                    @switch($status)
                        @case(\App\Enums\PoliceClearanceStatus::Missing) {{ __('You have not uploaded a police clearance certificate yet.') }} @break
                        @case(\App\Enums\PoliceClearanceStatus::PendingVerification) {{ __('Your certificate is waiting to be checked by WaveTeam. This usually takes 1–3 working days.') }} @break
                        @case(\App\Enums\PoliceClearanceStatus::Valid) {{ __('Your certificate has been verified. You are eligible for assignments that require police clearance.') }} @break
                        @case(\App\Enums\PoliceClearanceStatus::ExpiringSoon) {{ __('Your certificate expires soon. Apply for a new one now and upload it here to avoid interruption.') }} @break
                        @case(\App\Enums\PoliceClearanceStatus::Expired) {{ __('Your certificate has expired. You cannot be assigned to sites requiring police clearance until a new certificate is verified.') }} @break
                        @case(\App\Enums\PoliceClearanceStatus::Rejected) {{ __('Your last certificate could not be verified. Please see the reason below and upload a valid certificate.') }} @break
                    @endswitch
                </p>
            </section>

            {{-- History --}}
            <section class="rounded-2xl border border-slate-200 bg-white">
                <h2 class="px-6 pt-5 font-semibold text-navy-900">{{ __('Certificate history') }}</h2>
                @if ($history->isEmpty())
                    <p class="px-6 pb-6 pt-2 text-sm text-slate-500">{{ __('No certificates uploaded yet.') }}</p>
                @else
                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="border-y border-slate-100 bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                                <tr>
                                    <th class="px-6 py-2 font-medium">{{ __('Issued') }}</th>
                                    <th class="px-6 py-2 font-medium">{{ __('Uploaded') }}</th>
                                    <th class="px-6 py-2 font-medium">{{ __('Status') }}</th>
                                    <th class="px-6 py-2 font-medium">{{ __('Valid until') }}</th>
                                    <th class="px-6 py-2"><span class="sr-only">{{ __('File') }}</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($history as $record)
                                    <tr class="{{ $record->superseded_at ? 'text-slate-400' : '' }}">
                                        <td class="px-6 py-3">{{ $record->issue_date->format('j M Y') }}</td>
                                        <td class="px-6 py-3">{{ $record->uploaded_at->format('j M Y') }}</td>
                                        <td class="px-6 py-3">
                                            @if ($record->superseded_at && $record->id !== $current?->id)
                                                <span class="text-xs">{{ __('Replaced') }}</span>
                                            @else
                                                <x-status-badge :status="$record->displayStatus()" />
                                            @endif
                                        </td>
                                        <td class="px-6 py-3">{{ $record->renewalDate()?->format('j M Y') ?? '—' }}</td>
                                        <td class="px-6 py-3 text-right"><a href="{{ route('documents.file', $record->applicant_document_id) }}" target="_blank" rel="noopener" class="font-medium text-navy-600 hover:text-navy-800">{{ __('View') }}</a></td>
                                    </tr>
                                    @if ($record->verification_status === \App\Enums\PccVerificationStatus::Rejected && $record->rejection_reason)
                                        <tr><td colspan="5" class="bg-rose-50 px-6 py-2 text-xs text-rose-700">{{ __('Reason: :r', ['r' => $record->rejection_reason]) }}</td></tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>

        {{-- Upload --}}
        <aside>
            <form method="POST" action="{{ route('applicant.police-clearance.store') }}" enctype="multipart/form-data" class="sticky top-6 space-y-4 rounded-2xl border border-slate-200 bg-white p-5" novalidate>
                @csrf
                <h2 class="font-semibold text-navy-900">{{ $history->isEmpty() ? __('Upload certificate') : __('Upload new / renewed certificate') }}</h2>
                <x-form.input name="issue_date" type="date" :label="__('Issue date')" :max="now()->format('Y-m-d')" required />
                <x-form.input name="certificate_number" :label="__('Certificate / reference number')" :hint="__('If shown on the certificate')" autocomplete="off" />
                <x-form.input name="issuing_authority" :label="__('Issued by')" :hint="__('e.g. Police Headquarters, Colombo')" />
                <x-form.file name="file" :label="__('Certificate (scan or photo)')" :hint="__('PDF, JPG or PNG, up to :mb MB. All pages must be readable.', ['mb' => $maxMb])" required />
                <x-form.checkbox name="confirm_genuine">{{ __('I confirm this is a genuine, unaltered certificate issued to me.') }}</x-form.checkbox>
                <button type="submit" class="btn-primary w-full">@svg('heroicon-o-arrow-up-tray', 'h-4 w-4') {{ __('Submit for verification') }}</button>
                <p class="text-xs text-slate-500">{{ __('Certificates are normally valid for :m months from the issue date; WaveTeam confirms the exact renewal date when verifying.', ['m' => $validityMonths]) }}</p>
            </form>
        </aside>
    </div>
</x-layouts.portal>
