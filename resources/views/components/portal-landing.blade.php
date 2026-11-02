@props(['portal', 'eyebrow', 'title', 'intro', 'steps' => [], 'features' => [], 'registerLabel'])

<x-layouts.public :title="$portal->label()">
    <section class="bg-blueprint relative overflow-hidden pb-20 pt-32 text-white sm:pb-24 sm:pt-40">
        <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-teal-300">{{ $eyebrow }}</p>
            <h1 class="mt-4 max-w-3xl text-4xl font-bold leading-[1.1] tracking-tight sm:text-5xl">{{ $title }}</h1>
            <p class="mt-6 max-w-2xl text-lg leading-relaxed text-slate-300">{{ $intro }}</p>
            <div class="mt-10 flex flex-wrap gap-3">
                <a href="{{ $portal->route('register') }}" class="btn-accent !px-6 !py-3">{{ $registerLabel }} @svg('heroicon-m-arrow-right', 'h-4 w-4')</a>
                <a href="{{ $portal->route('login') }}" class="btn-ghost !px-6 !py-3">{{ __('Sign in') }}</a>
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
        <h2 class="text-sm font-semibold uppercase tracking-[0.16em] text-teal-600">{{ __('How it works') }}</h2>
        <ol class="mt-6 grid gap-4 md:grid-cols-3">
            @foreach ($steps as $i => [$heading, $text])
                <li class="rounded-2xl border border-slate-200 bg-white p-6">
                    <span class="grid h-9 w-9 place-items-center rounded-full bg-navy-600 text-sm font-bold text-white">{{ $i + 1 }}</span>
                    <h3 class="mt-4 font-semibold text-navy-900">{{ $heading }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ $text }}</p>
                </li>
            @endforeach
        </ol>
    </section>

    @if ($features)
        <section class="border-t border-slate-200 bg-white">
            <div class="mx-auto grid max-w-7xl gap-8 px-4 py-14 sm:px-6 md:grid-cols-3 lg:px-8">
                @foreach ($features as [$icon, $heading, $text])
                    <div>
                        <span class="grid h-11 w-11 place-items-center rounded-xl bg-volt-400/15 text-volt-600">@svg($icon, 'h-6 w-6')</span>
                        <h3 class="mt-4 font-semibold text-navy-900">{{ $heading }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ $text }}</p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</x-layouts.public>
