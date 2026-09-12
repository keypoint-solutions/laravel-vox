<script setup lang="ts">
    import {
        useVox,
        trans,
        trans_choice,
        transChoice,
        wTransChoice,
    } from '@laravel-vox/runtime.js';
    import { computed } from 'vue';

    const { locales } = useVox();
    const requestedLocale = document.documentElement.dataset.requestedLocale ?? document.documentElement.lang;
    const fallbackDemoLocale = document.documentElement.dataset.fallbackDemoLocale ?? 'und';
    const fallbackLocale = document.documentElement.dataset.fallbackLocale ?? 'en';
    const demoLocales = computed(() => [...new Set([...locales.value, fallbackDemoLocale])]);
    const isFallbackDemo = computed(() => requestedLocale === fallbackDemoLocale);
    const dynamicValueKey = 'label1';
    const backendOnlyKey = ['validation', 'accepted'].join('.');
    const reactiveChoiceTranslation = wTransChoice('frontend.Items selected', 3);

    function pageUrl(locale: string, page: 'blade' | 'vue'): string {
        return `/${locale}/${page}`;
    }
</script>

<template>
    <div class="min-h-screen bg-slate-950 text-slate-100">
        <header class="border-b border-white/10 bg-slate-950/90">
            <div class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-4 px-6 py-5">
                <div>
                    <p class="text-sm font-semibold">Laravel Vox consumer app</p>
                    <p class="mt-1 text-xs text-slate-400">Vue integration through the public Vox plugins</p>
                </div>
                <nav class="flex flex-wrap items-center gap-2 text-sm">
                    <a
                        :href="pageUrl(requestedLocale, 'blade')"
                        class="rounded-md px-3 py-2 text-slate-300 transition hover:bg-white/10 hover:text-white"
                    >
                        Blade page
                    </a>
                    <a
                        :href="pageUrl(requestedLocale, 'vue')"
                        class="rounded-md bg-violet-500 px-3 py-2 font-medium text-white"
                    >
                        Vue page
                    </a>
                    <a
                        href="/vox"
                        class="rounded-md px-3 py-2 text-slate-300 transition hover:bg-white/10 hover:text-white"
                    >
                        Vox manager
                    </a>
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-5xl space-y-8 px-6 py-12">
            <section class="max-w-2xl">
                <p class="text-xs font-semibold tracking-[0.22em] text-violet-300 uppercase">Vue consumer</p>
                <h1 class="mt-3 text-3xl font-semibold tracking-tight">{{ $t('frontend.Regular translation') }}</h1>
                <p class="mt-3 text-slate-400">
                    This page loads the same Laravel translation files as the backend, including JSON strings and
                    parameterized values.
                </p>
            </section>

            <section
                aria-label="Language picker"
                class="flex flex-wrap items-center gap-2"
            >
                <span class="mr-2 text-sm text-slate-400">Language</span>
                <a
                    v-for="locale in demoLocales"
                    :key="locale"
                    :href="pageUrl(locale, 'vue')"
                    :aria-current="locale === requestedLocale ? 'page' : undefined"
                    :class="[
                        'rounded-full border px-3 py-1.5 text-sm font-medium uppercase transition',
                        locale === requestedLocale
                            ? 'border-violet-400 bg-violet-400/15 text-violet-200'
                            : 'border-white/15 text-slate-300 hover:border-white/30 hover:text-white',
                    ]"
                >
                    {{ locale }}<span v-if="locale === fallbackDemoLocale"> · fallback</span>
                </a>
            </section>

            <section
                v-if="isFallbackDemo"
                data-test="fallback-notice"
                class="rounded-xl border border-amber-400/30 bg-amber-400/10 px-5 py-4 text-sm text-amber-100"
            >
                <p class="font-semibold">Intentional fallback demo</p>
                <p class="mt-1 text-amber-100/75">
                    <code>{{ fallbackDemoLocale }}</code> is absent from the locale catalogue, so the Vox plugin loads
                    <code>{{ fallbackLocale }}</code
                    >.
                </p>
            </section>

            <section class="grid gap-4 md:grid-cols-2">
                <article class="rounded-xl border border-white/10 bg-white/5 p-5">
                    <p class="text-xs tracking-wide text-slate-500 uppercase">PHP group</p>
                    <p
                        data-test="php-translation"
                        class="mt-2 text-lg font-medium"
                    >
                        {{ $t('frontend.Works.') }}
                    </p>
                </article>
                <article class="rounded-xl border border-white/10 bg-white/5 p-5">
                    <p class="text-xs tracking-wide text-slate-500 uppercase">Laravel JSON</p>
                    <p
                        data-test="json-translation"
                        class="mt-2 text-lg font-medium"
                    >
                        {{ $t('This ends up in JSON') }}
                    </p>
                </article>
                <article class="rounded-xl border border-white/10 bg-white/5 p-5">
                    <p class="text-xs tracking-wide text-slate-500 uppercase">Bound parameter</p>
                    <p
                        data-test="parameter-translation"
                        class="mt-2 text-lg font-medium"
                    >
                        {{ $t('frontend.Today is :date', { date: '24 July 2026' }) }}
                    </p>
                </article>
                <article class="rounded-xl border border-white/10 bg-white/5 p-5">
                    <p class="text-xs tracking-wide text-slate-500 uppercase">Nested key</p>
                    <p
                        data-test="nested-translation"
                        class="mt-2 text-lg font-medium"
                    >
                        {{ trans('frontend.dynamicLabels.labels.Dynamic Label 1') }}
                    </p>
                </article>
                <article class="rounded-xl border border-white/10 bg-white/5 p-5">
                    <p class="text-xs tracking-wide text-slate-500 uppercase">Dynamic lookup</p>
                    <p
                        data-test="dynamic-translation"
                        class="mt-2 text-lg font-medium"
                    >
                        {{ trans('frontend.dynamicLabels.labels.Dynamic Label 1') }}:
                        {{ $t('frontend.dynamicLabels.values.' + dynamicValueKey) }}
                    </p>
                </article>
                <article class="rounded-xl border border-white/10 bg-white/5 p-5">
                    <p class="text-xs tracking-wide text-slate-500 uppercase">Pluralization</p>
                    <div class="mt-3 space-y-2 text-sm">
                        <div class="flex items-baseline justify-between gap-4">
                            <code class="text-xs text-slate-500">transChoice()</code>
                            <span
                                data-test="choice-translation"
                                class="text-right font-medium"
                            >
                                {{ transChoice('frontend.Items selected', 2) }}
                            </span>
                        </div>
                        <div class="flex items-baseline justify-between gap-4">
                            <code class="text-xs text-slate-500">trans_choice()</code>
                            <span
                                data-test="choice-translation-alias"
                                class="text-right font-medium"
                            >
                                {{ trans_choice('frontend.Items selected', 1) }}
                            </span>
                        </div>
                        <div class="flex items-baseline justify-between gap-4">
                            <code class="text-xs text-slate-500">$tChoice()</code>
                            <span
                                data-test="choice-translation-global"
                                class="text-right font-medium"
                            >
                                {{ $tChoice('frontend.Items selected', 0) }}
                            </span>
                        </div>
                        <div class="flex items-baseline justify-between gap-4">
                            <code class="text-xs text-slate-500">wTransChoice()</code>
                            <span
                                data-test="choice-translation-reactive"
                                class="text-right font-medium"
                            >
                                {{ reactiveChoiceTranslation }}
                            </span>
                        </div>
                    </div>
                </article>
                <article class="rounded-xl border border-white/10 bg-white/5 p-5 md:col-span-2">
                    <p class="text-xs tracking-wide text-slate-500 uppercase">Bundle boundary</p>
                    <p
                        data-test="backend-only-translation"
                        class="mt-2 text-lg font-medium"
                    >
                        {{ $t(backendOnlyKey) }}
                    </p>
                    <p class="mt-1 text-xs text-slate-400">
                        This backend-only key deliberately remains unresolved because its group is not exported.
                    </p>
                </article>
            </section>
        </main>
    </div>
</template>
