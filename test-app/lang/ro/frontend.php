<?php

// @formatter:off
// phpcs:disable
// phpcs:ignoreFile
// phpstan-ignore-file
// psalm-disable-file

return [
    // text-sm"> <span class="text-muted-foreground">Short 2: </span> <span class="font-medium text-foreground">{{ $t(KEY) }}</span> </p> <p class="mt-2 text-sm">
    // resources/js/pages/Welcome.vue:116
    'Also works.' => 'Funcționează și acesta.',
    // <span class="text-muted-foreground">Multiline Phrase: </span> <span class="font-medium text-foreground">{{ $t(`KEY Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation
    // resources/js/pages/Welcome.vue:140
    'Lorem ipsum dolor sit amet, consectetur adipiscing elit.
                                Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.
                                Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit.  
                                Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.  
                                Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.',
    // text-sm"> <span class="text-muted-foreground">Phrase 1: </span> <span class="font-medium text-foreground">{{ $t(KEY) }}</span> </p> <p class="mt-2 text-sm">
    // resources/js/pages/Welcome.vue:128
    'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.',
    // text-sm"> <span class="text-muted-foreground">Regular: </span> <span class="font-medium text-foreground">{{ $t(KEY) }}</span> </p> <p class="mt-2 text-sm">
    // resources/js/pages/Welcome.vue:104
    'Regular translation' => 'Traducere regulată',
    // <div> <span class="text-muted-foreground">Parametrized 1: </span> <span class="font-medium text-foreground">{{ $t(KEY, {date: new Date().toLocaleDateString()}) }}</span> </div> <div>
    // resources/js/pages/Welcome.vue:170
    'Today is :date' => 'Astăzi este :date',
    // <div> <span class="text-muted-foreground">Parametrized 2: </span> <span class="font-medium text-foreground">{{ $t(KEY, {date: new Date().toLocaleDateString(), time: new Date().toLocaleTimeString()}) }}</span> </div> </div>
    // resources/js/pages/Welcome.vue:176
    'Today is :date, :time.' => 'Astăzi este :date, :time.',
    // text-sm"> <span class="text-muted-foreground">Short 1: </span> <span class="font-medium text-foreground">{{ $t(KEY) }}</span> </p> <p class="mt-2 text-sm">
    // resources/js/pages/Welcome.vue:110
    'Works.' => 'Funcționează.',
    // const dynamicLabels = computed(() => { return { 'label1': trans(KEY), 'label2': trans('frontend.dynamicLabels.labels.Dynamic Label 2'), }; });
    // resources/js/pages/Welcome.vue:24
    'dynamicLabels.labels.Dynamic Label 1' => 'Eticheta dinamică 1',
    // = computed(() => { return { 'label1': trans('frontend.dynamicLabels.labels.Dynamic Label 1'), 'label2': trans(KEY), }; });
    // resources/js/pages/Welcome.vue:25
    'dynamicLabels.labels.Dynamic Label 2' => 'Eticheta dinamică 2',
    'dynamicLabels.values.label1' => 'Valoarea etichetei 1',
    // const dynamicLabels2 = computed(() => { return { 'label1': trans(KEY), 'label2': trans('frontend.dynamicLabels2.labels.Dynamic Label 2'), }; });
    // resources/js/pages/Welcome.vue:31
    'dynamicLabels2.labels.Dynamic Label 1' => 'Eticheta dinamică 1',
    // = computed(() => { return { 'label1': trans('frontend.dynamicLabels2.labels.Dynamic Label 1'), 'label2': trans(KEY), }; }); </script>
    // resources/js/pages/Welcome.vue:32
    'dynamicLabels2.labels.Dynamic Label 2' => 'Eticheta dinamică 2',
    'dynamicLabels2.values.label1' => 'Valoarea etichetei 1',
    // text-sm"> <span class="text-muted-foreground">Phrase 2: </span> <span class="font-medium text-foreground">{{ $t(KEY) }}</span> </p> <p class="mt-2 text-sm">
    // resources/js/pages/Welcome.vue:134
    'grouped.Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.',
    // text-sm"> <span class="text-muted-foreground">Short 3: </span> <span class="font-medium text-foreground">{{ $t(KEY) }}</span> </p> <p class="mt-2 text-sm">
    // resources/js/pages/Welcome.vue:122
    'grouped.Works.' => 'Funcționează.',
];
