<x-layouts.public :title="$policy->title">
    <section class="bg-blueprint pb-14 pt-32 text-white sm:pt-36">
        <div class="mx-auto max-w-3xl px-4 sm:px-6">
            <h1 class="text-3xl font-bold tracking-tight sm:text-4xl">{{ $policy->title }}</h1>
            <p class="mt-3 text-sm text-slate-300">
                {{ __('Version :v', ['v' => $policy->version]) }} · {{ __('Published :date', ['date' => $policy->published_at->format('j F Y')]) }}
            </p>
        </div>
    </section>

    <article class="policy mx-auto max-w-3xl px-4 py-12 text-slate-700 sm:px-6">
        {!! $html !!}
    </article>
</x-layouts.public>
