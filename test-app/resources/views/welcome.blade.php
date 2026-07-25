<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >
    <title>Laravel Vox Blade consumer</title>
    @vite('resources/css/app.css')
</head>

<body class="min-h-screen bg-slate-950 text-slate-100">
    <header class="border-b border-white/10 bg-slate-950/90">
        <div class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-4 px-6 py-5">
            <div>
                <p class="text-sm font-semibold">Laravel Vox consumer app</p>
                <p class="mt-1 text-xs text-slate-400">Blade integration through Laravel's translator</p>
            </div>
            <nav class="flex flex-wrap items-center gap-2 text-sm">
                <a
                    href="{{ route('translations.blade', ['locale' => $locale]) }}"
                    class="rounded-md bg-violet-500 px-3 py-2 font-medium text-white"
                >
                    Blade page
                </a>
                <a
                    href="{{ route('translations.vue', ['locale' => $locale]) }}"
                    class="rounded-md px-3 py-2 text-slate-300 transition hover:bg-white/10 hover:text-white"
                >
                    Vue page
                </a>
                <a
                    href="{{ route('vox.dashboard') }}"
                    class="rounded-md px-3 py-2 text-slate-300 transition hover:bg-white/10 hover:text-white"
                >
                    Vox manager
                </a>
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-5xl space-y-8 px-6 py-12">
        @php($dynamicValueKey = 'label1')

        <section class="max-w-2xl">
            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-violet-300">Blade consumer</p>
            <h1 class="mt-3 text-3xl font-semibold tracking-tight">{{ __('frontend.Regular translation') }}</h1>
            <p class="mt-3 text-slate-400">
                This page uses Laravel's translator directly from the same language files loaded by the Vue page.
            </p>
        </section>

        <section
            aria-label="Language picker"
            class="flex flex-wrap items-center gap-2"
        >
            <span class="mr-2 text-sm text-slate-400">Language</span>
            @foreach ($availableLocales as $availableLocale)
                <a
                    href="{{ route('translations.blade', ['locale' => $availableLocale]) }}"
                    @if ($availableLocale === $locale) aria-current="page" @endif
                    @class([
                        'rounded-full border px-3 py-1.5 text-sm font-medium uppercase transition',
                        'border-violet-400 bg-violet-400/15 text-violet-200' =>
                            $availableLocale === $locale,
                        'border-white/15 text-slate-300 hover:border-white/30 hover:text-white' =>
                            $availableLocale !== $locale,
                    ])
                >
                    {{ $availableLocale }}
                    @if ($availableLocale === $fallbackDemoLocale)
                        <span>· fallback</span>
                    @endif
                </a>
            @endforeach
        </section>

        @if ($locale === $fallbackDemoLocale)
            <section
                data-test="fallback-notice"
                class="rounded-xl border border-amber-400/30 bg-amber-400/10 px-5 py-4 text-sm text-amber-100"
            >
                <p class="font-semibold">Intentional fallback demo</p>
                <p class="mt-1 text-amber-100/75">
                    <code>{{ $fallbackDemoLocale }}</code> is not a configured application locale, so Laravel falls
                    back to <code>{{ $fallbackLocale }}</code>.
                </p>
            </section>
        @endif

        <section class="grid gap-4 md:grid-cols-2">
            <article class="rounded-xl border border-white/10 bg-white/5 p-5">
                <p class="text-xs uppercase tracking-wide text-slate-500">PHP group</p>
                <p
                    data-test="php-translation"
                    class="mt-2 text-lg font-medium"
                >{{ __('frontend.Works.') }}</p>
            </article>
            <article class="rounded-xl border border-white/10 bg-white/5 p-5">
                <p class="text-xs uppercase tracking-wide text-slate-500">Laravel JSON</p>
                <p
                    data-test="json-translation"
                    class="mt-2 text-lg font-medium"
                >{{ __('This ends up in JSON') }}</p>
            </article>
            <article class="rounded-xl border border-white/10 bg-white/5 p-5">
                <p class="text-xs uppercase tracking-wide text-slate-500">Bound parameter</p>
                <p
                    data-test="parameter-translation"
                    class="mt-2 text-lg font-medium"
                >
                    {{ __('frontend.Today is :date', ['date' => '24 July 2026']) }}
                </p>
            </article>
            <article class="rounded-xl border border-white/10 bg-white/5 p-5">
                <p class="text-xs uppercase tracking-wide text-slate-500">Nested key</p>
                <p
                    data-test="nested-translation"
                    class="mt-2 text-lg font-medium"
                >
                    {{ __('frontend.dynamicLabels.labels.Dynamic Label 1') }}
                </p>
            </article>
            <article class="rounded-xl border border-white/10 bg-white/5 p-5">
                <p class="text-xs uppercase tracking-wide text-slate-500">Dynamic lookup</p>
                <p
                    data-test="dynamic-translation"
                    class="mt-2 text-lg font-medium"
                >
                    {{ __('frontend.dynamicLabels.labels.Dynamic Label 1') }}:
                    {{ __('frontend.dynamicLabels.values.' . $dynamicValueKey) }}
                </p>
            </article>
            <article class="rounded-xl border border-white/10 bg-white/5 p-5">
                <p class="text-xs uppercase tracking-wide text-slate-500">Pluralization</p>
                <div class="mt-3 space-y-2 text-sm">
                    <div class="flex items-baseline justify-between gap-4">
                        <code class="text-xs text-slate-500">trans_choice()</code>
                        <span
                            data-test="choice-translation"
                            class="text-right font-medium"
                        >
                            {{ trans_choice('frontend.Items selected', 2, ['count' => 2]) }}
                        </span>
                    </div>
                    <div class="flex items-baseline justify-between gap-4">
                        <code class="text-xs text-slate-500">@@choice</code>
                        <span
                            data-test="choice-translation-directive"
                            class="text-right font-medium"
                        >
                            @choice('frontend.Items selected', 1, ['count' => 1])
                        </span>
                    </div>
                    <div class="flex items-baseline justify-between gap-4">
                        <code class="text-xs text-slate-500">Lang::choice()</code>
                        <span
                            data-test="choice-translation-facade"
                            class="text-right font-medium"
                        >
                            {{ \Illuminate\Support\Facades\Lang::choice('frontend.Items selected', 0, ['count' => 0]) }}
                        </span>
                    </div>
                    <div class="flex items-baseline justify-between gap-4">
                        <code class="text-xs text-slate-500">translator-&gt;choice()</code>
                        <span
                            data-test="choice-translation-instance"
                            class="text-right font-medium"
                        >
                            {{ app('translator')->choice('frontend.Items selected', 3, ['count' => 3]) }}
                        </span>
                    </div>
                </div>
            </article>
        </section>
    </main>
</body>

</html>
