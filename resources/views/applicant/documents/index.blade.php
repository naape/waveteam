<x-layouts.portal :title="__('Documents')" :portal="\App\Enums\Portal::Applicant">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-navy-900">{{ __('My documents') }}</h1>
            <p class="mt-1 text-slate-600">{{ __('Files are encrypted and visible only to you and authorised WaveTeam staff.') }}</p>
        </div>
        <a href="{{ route('applicant.police-clearance.index') }}" class="btn-outline">@svg('heroicon-o-shield-check', 'h-4 w-4') {{ __('Police clearance') }}</a>
    </div>

    <div class="mt-8 grid gap-6 lg:grid-cols-[1fr_22rem]">
        <section class="space-y-4">
            @foreach ($types as $type => $meta)
                @php $docs = $documents->get($type, collect()); $current = $docs->where('is_current', true); $history = $docs->where('is_current', false); @endphp
                <div class="rounded-2xl border border-slate-200 bg-white">
                    <div class="flex items-center justify-between gap-4 px-5 py-4">
                        <div>
                            <h2 class="font-semibold text-navy-900">{{ $meta['label'] }}</h2>
                            @if ($meta['hint'])<p class="text-xs text-slate-500">{{ $meta['hint'] }}</p>@endif
                        </div>
                        @if ($current->isEmpty())
                            <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">{{ __('Not uploaded') }}</span>
                        @endif
                    </div>
                    @if ($docs->isNotEmpty())
                        <ul class="divide-y divide-slate-100 border-t border-slate-100">
                            @foreach ($current->concat($history) as $doc)
                                <li class="flex flex-wrap items-center gap-3 px-5 py-3 text-sm {{ $doc->is_current ? '' : 'bg-slate-50/60 text-slate-500' }}">
                                    @svg($doc->file->mime_type === 'application/pdf' ? 'heroicon-o-document-text' : 'heroicon-o-photo', 'h-5 w-5 shrink-0 text-slate-400')
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate font-medium {{ $doc->is_current ? 'text-slate-800' : '' }}">{{ $doc->title ?: $doc->file->original_name }}</p>
                                        <p class="text-xs text-slate-500">
                                            {{ __('Version :v', ['v' => $doc->version]) }} · {{ $doc->created_at->format('j M Y') }} · {{ \Illuminate\Support\Number::fileSize($doc->file->size_bytes) }}
                                        </p>
                                    </div>
                                    <x-status-badge :status="$doc->status" />
                                    <a href="{{ route('documents.file', $doc) }}" target="_blank" rel="noopener" class="text-sm font-medium text-navy-600 hover:text-navy-800">{{ __('View') }}</a>
                                </li>
                                @if ($doc->status === \App\Enums\DocumentStatus::Rejected && $doc->rejection_reason)
                                    <li class="bg-rose-50 px-5 py-2 text-xs text-rose-700">{{ __('Reason: :r', ['r' => $doc->rejection_reason]) }}</li>
                                @endif
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endforeach
        </section>

        <aside>
            <form method="POST" action="{{ route('applicant.documents.store') }}" enctype="multipart/form-data" class="sticky top-6 space-y-4 rounded-2xl border border-slate-200 bg-white p-5" novalidate>
                @csrf
                <h2 class="font-semibold text-navy-900">{{ __('Upload a document') }}</h2>
                <x-form.select name="document_type" :label="__('Document type')" :options="collect($types)->map(fn ($t) => $t['label'])->all()" :placeholder="__('Select…')" required />
                <x-form.input name="title" :label="__('Description (optional)')" :hint="__('e.g. NVQ Level 4 certificate')" />
                <x-form.file name="file" :label="__('File')" :hint="__('PDF, JPG or PNG, up to :mb MB.', ['mb' => $maxMb])" required />
                <button type="submit" class="btn-primary w-full">@svg('heroicon-o-arrow-up-tray', 'h-4 w-4') {{ __('Upload') }}</button>
                <p class="text-xs text-slate-500">{{ __('Uploading a new CV, NIC or licence copy replaces the current one; earlier versions are kept in your history.') }}</p>
            </form>
        </aside>
    </div>
</x-layouts.portal>
