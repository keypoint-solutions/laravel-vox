<?php

// @formatter:off
// phpcs:disable
// phpcs:ignoreFile
// phpstan-ignore-file
// psalm-disable-file

return [
    // 22em] text-violet-300 uppercase">Vue consumer</p> <h1 class="mt-3 text-3xl font-semibold tracking-tight">{{ $t(KEY) }}</h1> <p class="mt-3 text-slate-400"> This page loads the same Laravel translation files as the backend, including
    // resources/js/pages/Welcome.vue:48
    'Regular translation' => 'Regular translation',
    'Items selected' => '{0} No items selected|{1} :count item selected|[2,*] :count items selected',
    // data-test="parameter-translation" class="mt-2 text-lg font-medium" > {{ $t(KEY, { date: '24 July 2026' }) }} </p> </article> <article class="rounded-xl border border-white/10 bg-white/5 p-5">
    // resources/js/pages/Welcome.vue:101
    'Today is :date' => 'Today is :date',
    // data-test="php-translation" class="mt-2 text-lg font-medium" > {{ $t(KEY) }} </p> </article> <article class="rounded-xl border border-white/10 bg-white/5 p-5">
    // resources/js/pages/Welcome.vue:83
    'Works.' => 'Works.',
    // data-test="dynamic-translation" class="mt-2 text-lg font-medium" > {{ trans(KEY) }}: {{ $t('frontend.dynamicLabels.values.' + dynamicValueKey) }} </p> </article>
    // resources/js/pages/Welcome.vue:110
    'dynamicLabels.labels.Dynamic Label 1' => 'Dynamic Label 1',
    'dynamicLabels.values.label1' => 'Label 1 value',
    'dynamicLabels2.values.label1' => 'Label 1 value',
    // 🗑️ 'Also works.' => 'Also works.',
    // 🗑️ 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.',
    // 🗑️ 'Today is :date, :time.' => 'Today is :date, :time.',
    // 🗑️ 'dynamicLabels.labels.Dynamic Label 2' => 'Dynamic Label 2',
    // 🗑️ 'dynamicLabels2.labels.Dynamic Label 1' => 'Dynamic Label 1',
    // 🗑️ 'dynamicLabels2.labels.Dynamic Label 2' => 'Dynamic Label 2',
    // 🗑️ 'grouped.Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.',
    // 🗑️ 'grouped.Works.' => 'Works.',
];
