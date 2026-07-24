<?php

use Illuminate\Support\Env;

it('scans Laravel and optional Cashier sources by default', function () {
    $defaultConfig = require __DIR__.'/../config/vox.php';

    expect($defaultConfig['parse']['paths'])
        ->toContain('/vendor/laravel/framework/src')
        ->toContain('/vendor/laravel/cashier/src');
});

it('normalizes comma separated and single env list values', function (): void {
    $environment = Env::getRepository();
    $frontendGroups = $environment->get('VOX_FRONTEND_GROUPS');
    $translateLocales = $environment->get('VOX_TRANSLATE_LOCALES');

    try {
        $environment->set('VOX_FRONTEND_GROUPS', 'frontend, checkout');
        $environment->set('VOX_TRANSLATE_LOCALES', 'fr');

        $config = require __DIR__.'/../config/vox.php';
    } finally {
        $frontendGroups === null
            ? $environment->clear('VOX_FRONTEND_GROUPS')
            : $environment->set('VOX_FRONTEND_GROUPS', $frontendGroups);
        $translateLocales === null
            ? $environment->clear('VOX_TRANSLATE_LOCALES')
            : $environment->set('VOX_TRANSLATE_LOCALES', $translateLocales);
    }

    expect($config['frontend']['groups'])->toBe(['frontend', 'checkout'])
        ->and($config['translate']['locales'])->toBe(['fr']);
});

it('uses a brief prompt with immutable Laravel value safeguards', function (): void {
    $config = require __DIR__.'/../config/vox.php';
    $prompt = $config['translate']['prompt'];

    expect($prompt)
        ->toContain('Return only the translation')
        ->toContain('Laravel placeholders')
        ->toContain('HTML or Markdown markup exactly')
        ->not->toContain(':text');
});
