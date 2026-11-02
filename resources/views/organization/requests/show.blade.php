@php
    use App\Enums\QuotationStatus;
    use App\Enums\ServiceRequestStatus;
    $r = $serviceRequest;
    $flow = [ServiceRequestStatus::Submitted, ServiceRequestStatus::UnderReview, ServiceRequestStatus::QuotationSent, ServiceRequestStatus::Approved, ServiceRequestStatus::TeamAssigned, ServiceRequestStatus::InProgress, ServiceRequestStatus::Completed];
    $order = array_flip(array_map(fn ($s) => $s->value, $flow));
    $currentIndex = $order[$r->status->value] ?? ($r->status === ServiceRequestStatus::QuotationPreparation ? 1 : -1);
@endphp

<x-layouts.portal :title="$r->reference" :portal="\App\Enums\Portal::Organization">
    <a href="{{ route('organization.requests.index') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-navy-700">@svg('heroicon-m-arrow-left', 'h-4 w-4') {{ __('All requests') }}</a>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="font-mono text-xs font-semibold text-teal-600">{{ $r->reference }} · {{ $r->serviceCategory->name }}</p>
            <h1 class="mt-1 text-2xl font-bold tracking-tight text-navy-900">{{ $r->title }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ __('Submitted :d by :n', ['d' => $r->created_at->format('j M Y'), 'n' => $r->submitter?->name]) }}</p>
        </div>
        <x-status-badge :status="$r->status" class="!px-3 !py-1 !text-sm" />
    </div>

    {{-- Progress --}}
    @if ($r->status !== ServiceRequestStatus::Cancelled)
        <ol class="mt-6 grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-7" aria-label="{{ __('Progress') }}">
            @foreach ($flow as $i => $step)
                @php $state = $i < $currentIndex ? 'done' : ($i === $currentIndex ? 'current' : 'todo'); @endphp
                <li class="rounded-lg border px-3 py-2 text-xs font-medium {{ $state === 'done' ? 'border-volt-200 bg-volt-50 text-volt-700' : ($state === 'current' ? 'border-navy-500 bg-navy-600 text-white' : 'border-slate-200 bg-white text-slate-400') }}"
                    @if ($state === 'current') aria-current="step" @endif>
                    {{ $step->getLabel() }}
                </li>
            @endforeach
        </ol>
    @else
        <p class="mt-6 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ __('Cancelled on :d.', ['d' => $r->cancelled_at?->format('j M Y')]) }} @if ($r->cancel_reason) {{ __('Reason: :r', ['r' => $r->cancel_reason]) }} @endif</p>
    @endif

    <div class="mt-8 grid gap-6 lg:grid-cols-[1fr_22rem]">
        <div class="min-w-0 space-y-6">
            {{-- Quotations --}}
            @if ($r->quotations->isNotEmpty())
                <section id="quotations" class="rounded-2xl border border-slate-200 bg-white p-6">
                    <h2 class="font-semibold text-navy-900">{{ __('Quotations') }}</h2>
                    @foreach ($r->quotations as $q)
                        @php $open = $q->status === QuotationStatus::Sent && (! $q->valid_until || ! $q->valid_until->isPast()) && $r->status === ServiceRequestStatus::QuotationSent; @endphp
                        <div class="mt-4 rounded-xl border {{ $open ? 'border-navy-300 ring-2 ring-navy-100' : 'border-slate-200' }}">
                            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
                                <div>
                                    <p class="font-mono text-sm font-semibold text-slate-900">{{ $q->reference }} <span class="font-sans font-normal text-slate-500">· v{{ $q->version }}</span></p>
                                    <p class="text-xs text-slate-500">{{ __('Sent :d', ['d' => $q->sent_at?->format('j M Y')]) }}@if ($q->valid_until) · {{ __('valid until :d', ['d' => $q->valid_until->format('j M Y')]) }}@endif</p>
                                </div>
                                <div class="flex items-center gap-3">
                                    <x-status-badge :status="$q->status" />
                                    @if ($q->pdf)
                                        <a href="{{ route('organization.files.show', [$q->pdf, 'download' => 1]) }}" class="inline-flex items-center gap-1 text-sm font-medium text-navy-600 hover:text-navy-800">@svg('heroicon-o-arrow-down-tray', 'h-4 w-4') PDF</a>
                                    @endif
                                </div>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead class="text-xs uppercase tracking-wider text-slate-500">
                                        <tr><th class="px-4 py-2 text-left font-medium">{{ __('Item') }}</th><th class="px-4 py-2 text-right font-medium">{{ __('Qty') }}</th><th class="px-4 py-2 text-right font-medium">{{ __('Unit price') }}</th><th class="px-4 py-2 text-right font-medium">{{ __('Amount') }}</th></tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        @foreach ($q->items as $item)
                                            <tr><td class="px-4 py-2">{{ $item->description }}</td><td class="whitespace-nowrap px-4 py-2 text-right">{{ rtrim(rtrim($item->quantity, '0'), '.') }} {{ $item->unit }}</td><td class="whitespace-nowrap px-4 py-2 text-right">{{ number_format($item->unit_price, 2) }}</td><td class="whitespace-nowrap px-4 py-2 text-right">{{ number_format($item->line_total, 2) }}</td></tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot class="text-sm">
                                        <tr><td colspan="3" class="px-4 pt-3 text-right text-slate-500">{{ __('Subtotal') }}</td><td class="px-4 pt-3 text-right">{{ number_format($q->subtotal, 2) }}</td></tr>
                                        <tr><td colspan="3" class="px-4 text-right text-slate-500">{{ __('Tax') }}</td><td class="px-4 text-right">{{ number_format($q->tax_amount, 2) }}</td></tr>
                                        <tr><td colspan="3" class="px-4 pb-3 text-right font-semibold text-slate-900">{{ __('Total (:c)', ['c' => $q->currency]) }}</td><td class="px-4 pb-3 text-right text-base font-bold text-navy-900">{{ number_format($q->total, 2) }}</td></tr>
                                    </tfoot>
                                </table>
                            </div>
                            @if ($q->terms)
                                <p class="whitespace-pre-line border-t border-slate-100 px-4 py-3 text-xs text-slate-500">{{ $q->terms }}</p>
                            @endif
                            @if ($q->client_comment)
                                <p class="border-t border-slate-100 px-4 py-3 text-sm text-slate-600">{{ __('Your comment:') }} {{ $q->client_comment }}</p>
                            @endif

                            @if ($open)
                                <div class="grid gap-4 border-t border-slate-100 bg-slate-50 p-4 sm:grid-cols-2">
                                    <form method="POST" action="{{ route('organization.quotations.respond', $q) }}" class="space-y-3" novalidate>
                                        @csrf
                                        <input type="hidden" name="decision" value="accept">
                                        <x-form.checkbox name="confirm">{{ __('I am authorised to accept this quotation on behalf of :org.', ['org' => auth()->user()->organizationMembership->organization->name]) }}</x-form.checkbox>
                                        <label for="accept_comment" class="block text-sm font-medium text-slate-700">{{ __('Comment (optional)') }}</label>
                                        <textarea id="accept_comment" name="comment" rows="2" class="block w-full rounded-lg border-slate-300 text-sm focus:border-navy-500 focus:ring-navy-500"></textarea>
                                        <button type="submit" class="btn-primary w-full">@svg('heroicon-o-check', 'h-4 w-4') {{ __('Accept quotation') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('organization.quotations.respond', $q) }}" class="space-y-3" novalidate>
                                        @csrf
                                        <input type="hidden" name="decision" value="reject">
                                        <label for="reject_comment" class="block text-sm font-medium text-slate-700">{{ __('Reason for rejecting') }}</label>
                                        <textarea id="reject_comment" name="comment" rows="4" class="block w-full rounded-lg border-slate-300 text-sm focus:border-navy-500 focus:ring-navy-500"></textarea>
                                        @error('comment')<p class="text-sm text-rose-600">{{ $message }}</p>@enderror
                                        <button type="submit" class="btn-outline w-full">{{ __('Reject quotation') }}</button>
                                    </form>
                                    @error('quotation')<p class="text-sm text-rose-600 sm:col-span-2">{{ $message }}</p>@enderror
                                </div>
                            @endif
                        </div>
                    @endforeach
                </section>
            @endif

            {{-- Assigned team --}}
            @if ($team->isNotEmpty())
                <section class="rounded-2xl border border-slate-200 bg-white p-6">
                    <h2 class="font-semibold text-navy-900">{{ __('Assigned team') }}</h2>
                    <ul class="mt-4 divide-y divide-slate-100">
                        @foreach ($team as $member)
                            <li class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                                <div>
                                    <p class="font-medium text-slate-900">{{ $member->applicantProfile->full_name }}</p>
                                    <p class="text-slate-500">{{ $member->role_title }} · {{ $member->start_date->format('j M Y') }}@if ($member->end_date) – {{ $member->end_date->format('j M Y') }}@endif</p>
                                </div>
                                <span class="inline-flex items-center gap-1 text-xs font-medium text-volt-700">@svg('heroicon-s-check-badge', 'h-4 w-4') {{ __('WaveTeam verified') }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- Messages --}}
            <section id="messages" class="rounded-2xl border border-slate-200 bg-white p-6">
                <h2 class="font-semibold text-navy-900">{{ __('Messages with WaveTeam') }}</h2>
                <ul class="mt-4 space-y-4">
                    @forelse ($r->messages as $m)
                        @php $fromStaff = $m->sender?->isStaff(); @endphp
                        <li class="flex {{ $fromStaff ? '' : 'justify-end' }}">
                            <div class="max-w-[85%] rounded-2xl px-4 py-3 text-sm {{ $fromStaff ? 'rounded-tl-sm bg-slate-100 text-slate-800' : 'rounded-tr-sm bg-navy-600 text-white' }}">
                                <p class="text-xs font-semibold {{ $fromStaff ? 'text-teal-700' : 'text-navy-100' }}">{{ $fromStaff ? __('WaveTeam') : $m->sender?->name }} · {{ $m->created_at->format('j M, H:i') }}</p>
                                <p class="mt-1 whitespace-pre-line">{{ $m->body }}</p>
                                @foreach ($m->files as $f)
                                    <a href="{{ route('organization.files.show', [$f, 'download' => 1]) }}" class="mt-2 inline-flex items-center gap-1 text-xs font-medium underline {{ $fromStaff ? 'text-navy-700' : 'text-white' }}">@svg('heroicon-o-paper-clip', 'h-3.5 w-3.5') {{ $f->original_name }}</a>
                                @endforeach
                            </div>
                        </li>
                    @empty
                        <li class="text-sm text-slate-500">{{ __('No messages yet. Ask us anything about this request.') }}</li>
                    @endforelse
                </ul>

                @if ($r->status !== ServiceRequestStatus::Cancelled)
                    <form method="POST" action="{{ route('organization.requests.messages.store', $r) }}" enctype="multipart/form-data" class="mt-6 space-y-3 border-t border-slate-100 pt-4" novalidate>
                        @csrf
                        <label for="body" class="sr-only">{{ __('Message') }}</label>
                        <textarea id="body" name="body" rows="3" required placeholder="{{ __('Write a message…') }}" class="block w-full rounded-lg border-slate-300 text-sm focus:border-navy-500 focus:ring-navy-500">{{ old('body') }}</textarea>
                        @error('body')<p class="text-sm text-rose-600">{{ $message }}</p>@enderror
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <input name="files[]" type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx" aria-label="{{ __('Attach files') }}" class="text-xs text-slate-500 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-slate-700">
                            <button type="submit" class="btn-primary">@svg('heroicon-o-paper-airplane', 'h-4 w-4') {{ __('Send') }}</button>
                        </div>
                        @foreach ($errors->getMessages() as $key => $msgs)
                            @if (str_starts_with($key, 'files'))<p class="text-sm text-rose-600">{{ $msgs[0] }}</p>@endif
                        @endforeach
                    </form>
                @endif
            </section>
        </div>

        <aside class="space-y-6">
            {{-- Details --}}
            <section class="rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="font-semibold text-navy-900">{{ __('Request details') }}</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div><dt class="text-slate-500">{{ __('Team size') }}</dt><dd class="font-medium text-slate-900">{{ trans_choice(':count person|:count people', $r->required_headcount) }}@if ((float) $r->required_experience_years > 0), {{ __(':y+ yrs experience', ['y' => (float) $r->required_experience_years]) }}@endif</dd></div>
                    <div><dt class="text-slate-500">{{ __('Location') }}</dt><dd class="font-medium text-slate-900">{{ $r->location_text }}, {{ $r->district?->localizedName() }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('Start / duration') }}</dt><dd class="font-medium text-slate-900">{{ $r->start_date?->format('j M Y') ?? __('Flexible') }}@if ($r->expected_duration_value) · {{ $r->expected_duration_value }} {{ __($r->expected_duration_unit) }}@endif</dd></div>
                    @if ($r->working_hours)<div><dt class="text-slate-500">{{ __('Working hours') }}</dt><dd class="font-medium text-slate-900">{{ $r->working_hours }}</dd></div>@endif
                    <div><dt class="text-slate-500">{{ __('Police clearance') }}</dt><dd class="font-medium text-slate-900">{{ $r->requires_police_clearance ? __('Required') : __('Not required') }}</dd></div>
                    @if ($r->budget_amount)<div><dt class="text-slate-500">{{ __('Budget') }}</dt><dd class="font-medium text-slate-900">{{ $r->budget_currency }} {{ number_format($r->budget_amount, 2) }}</dd></div>@endif
                    @if ($r->skills->isNotEmpty())<div><dt class="text-slate-500">{{ __('Skills') }}</dt><dd class="mt-1 flex flex-wrap gap-1">@foreach ($r->skills as $s)<span class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-700">{{ $s->name }}</span>@endforeach</dd></div>@endif
                </dl>
                <details class="mt-4 border-t border-slate-100 pt-3 text-sm">
                    <summary class="cursor-pointer font-medium text-navy-600">{{ __('Description & requirements') }}</summary>
                    <p class="mt-2 whitespace-pre-line text-slate-700">{{ $r->description }}</p>
                    @if ($r->required_qualifications)<p class="mt-3 text-xs font-semibold uppercase text-slate-500">{{ __('Qualifications') }}</p><p class="whitespace-pre-line text-slate-700">{{ $r->required_qualifications }}</p>@endif
                    @if ($r->special_requirements)<p class="mt-3 text-xs font-semibold uppercase text-slate-500">{{ __('Special requirements') }}</p><p class="whitespace-pre-line text-slate-700">{{ $r->special_requirements }}</p>@endif
                </details>
            </section>

            {{-- Documents --}}
            <section class="rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="font-semibold text-navy-900">{{ __('Documents') }}</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    @forelse ($r->attachments as $a)
                        <li><a href="{{ route('organization.files.show', [$a->file, 'download' => 1]) }}" class="flex items-center gap-2 text-navy-600 hover:text-navy-800">@svg('heroicon-o-paper-clip', 'h-4 w-4 shrink-0') <span class="truncate">{{ $a->title ?: $a->file->original_name }}</span></a></li>
                    @empty
                        <li class="text-slate-500">{{ __('No documents.') }}</li>
                    @endforelse
                </ul>
            </section>

            {{-- History --}}
            <section class="rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="font-semibold text-navy-900">{{ __('History') }}</h2>
                <ol class="mt-3 space-y-3 border-l-2 border-slate-100 pl-4 text-sm">
                    @foreach ($r->statusHistory as $h)
                        <li>
                            <p class="font-medium text-slate-800">{{ \App\Enums\ServiceRequestStatus::tryFrom($h->to_status)?->getLabel() ?? $h->to_status }}</p>
                            <p class="text-xs text-slate-500">{{ $h->created_at->format('j M Y, H:i') }}</p>
                        </li>
                    @endforeach
                </ol>
            </section>

            @if ($canCancel)
                <details class="rounded-2xl border border-rose-200 bg-white p-5 text-sm">
                    <summary class="cursor-pointer font-medium text-rose-700">{{ __('Cancel this request') }}</summary>
                    <form method="POST" action="{{ route('organization.requests.cancel', $r) }}" class="mt-3 space-y-3" novalidate>
                        @csrf
                        <x-form.textarea name="reason" :label="__('Reason')" rows="2" required />
                        <button type="submit" class="btn w-full bg-rose-600 text-white hover:bg-rose-700">{{ __('Cancel request') }}</button>
                    </form>
                </details>
            @endif
        </aside>
    </div>
</x-layouts.portal>
