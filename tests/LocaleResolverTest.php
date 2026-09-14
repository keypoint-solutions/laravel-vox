<?php

use KeypointSolutions\LaravelVox\Support\VoxLocaleResolver;

it('uses the application locale as the automatic base and sorts discovered locales', function (): void {
    config()->set('app.locale', 'en');
    config()->set('vox.translate.base_locale', 'auto');
    config()->set('vox.translate.locales.mode', 'auto');
    config()->set('laravellocalization.supportedLocales', ['de' => [], 'en' => [], 'fr' => [], 'ar' => []]);

    $resolver = app(VoxLocaleResolver::class);

    expect($resolver->resolveBaseLocale(['de', 'en', 'ar']))->toBe('en')
        ->and($resolver->resolveLocales())->toBe(['en', 'ar', 'de', 'fr']);
});

it('puts an explicit base first and sorts configured locales', function (): void {
    config()->set('app.locale', 'en');
    config()->set('vox.translate.base_locale', 'fr');
    config()->set('vox.translate.locales', ['mode' => 'configured', 'values' => ['de', 'en', 'ar', 'fr']]);

    $resolver = app(VoxLocaleResolver::class);

    expect($resolver->resolveBaseLocale(['de', 'en']))->toBe('fr')
        ->and($resolver->resolveLocales())->toBe(['fr', 'ar', 'de', 'en']);
});

it('includes the application base when it is absent from the locale list', function (): void {
    config()->set('app.locale', 'en');
    config()->set('vox.translate.base_locale', 'auto');
    config()->set('vox.translate.locales', ['mode' => 'configured', 'values' => ['fr', 'de']]);

    expect(app(VoxLocaleResolver::class)->resolveLocales())->toBe(['en', 'de', 'fr']);
});
