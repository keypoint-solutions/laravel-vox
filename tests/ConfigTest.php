<?php

use KeypointSolutions\LaravelVox\Support\VoxListConfiguration;

it('scans Laravel and optional Cashier sources by default', function () {
    $defaultConfig = require __DIR__.'/../config/vox.php';

    expect($defaultConfig['parse']['paths'])
        ->toContain('/vendor/laravel/framework/src')
        ->toContain('/vendor/laravel/cashier/src');
});

it('retains Laravel runtime-generated translation families by default', function (): void {
    $defaultConfig = require __DIR__.'/../config/vox.php';

    expect($defaultConfig['retained_keys'])->toBe([
        'auth.*',
        'pagination.*',
        'passwords.*',
        'validation.*',
    ])->and($defaultConfig['dynamic_keys']['bindings'])->toBe([])
        ->and($defaultConfig['parse'])->not->toHaveKey('protected_keys');
});

it('keeps configurable lists declarative', function (): void {
    $config = require __DIR__.'/../config/vox.php';

    expect($config['frontend']['groups'])->toBe([
        'mode' => 'auto',
        'values' => '',
    ])->and($config['translate']['locales'])->toBe([
        'mode' => 'auto',
        'values' => '',
    ]);
});

it('normalizes configured list arrays, single values, and comma separated strings', function (): void {
    $configuration = app(VoxListConfiguration::class);

    config()->set('vox.frontend.groups.mode', 'configured');
    config()->set('vox.frontend.groups.values', 'frontend, checkout, frontend');
    config()->set('vox.translate.locales.mode', 'configured');
    config()->set('vox.translate.locales.values', 'fr');

    expect($configuration->configuredValues('vox.frontend.groups'))
        ->toBe(['frontend', 'checkout'])
        ->and($configuration->configuredValues('vox.translate.locales'))
        ->toBe(['fr']);

    config()->set('vox.translate.locales.values', ['en', ' fr ', '', 'en']);

    expect($configuration->configuredValues('vox.translate.locales'))->toBe(['en', 'fr']);
});

it('ignores list values while automatic mode is active', function (): void {
    config()->set('vox.frontend.groups.mode', 'auto');
    config()->set('vox.frontend.groups.values', 'frontend,checkout');

    expect(app(VoxListConfiguration::class)->configuredValues('vox.frontend.groups'))->toBeNull();
});

it('uses a brief prompt with immutable Laravel value safeguards', function (): void {
    $config = require __DIR__.'/../config/vox.php';
    $prompt = $config['translate']['prompt'];

    expect($prompt)
        ->toContain('naturally and idiomatically')
        ->toContain('same meaning and register in context')
        ->toContain('do not mirror the source wording')
        ->toContain('Return only the translation')
        ->toContain('Laravel placeholders')
        ->toContain('HTML or Markdown markup exactly')
        ->not->toContain(':text');
});

it('keeps runtime frontend delivery opt in', function (): void {
    $config = require __DIR__.'/../config/vox.php';

    expect($config['frontend']['runtime'])
        ->toMatchArray([
            'enabled' => false,
            'middleware' => [],
        ])
        ->and($config['frontend']['runtime']['path'])
        ->toEndWith('storage/vox/frontend-translations');
});
