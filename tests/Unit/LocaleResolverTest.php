<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Facades\LaravelVox;
use KeypointSolutions\LaravelVox\Support\VoxSettingsRepository;
use KeypointSolutions\LaravelVox\Translation\Locales\VoxLocaleResolver;

beforeEach(function (): void {
    $this->localeRoot = base_path('tests/.tmp/locales-'.Str::uuid());
    File::makeDirectory($this->localeRoot.'/lang', 0755, true);
    config()->set('vox.paths.lang', $this->localeRoot.'/lang');
    config()->set('vox.frontend.runtime.path', $this->localeRoot.'/runtime');
});

afterEach(function (): void {
    File::deleteDirectory($this->localeRoot);
});

it('uses the application locale as the automatic base and sorts discovered locales', function (): void {
    config()->set('app.locale', 'en');
    config()->set('vox.translate.base_locale', 'auto');
    config()->set('vox.translate.locales.mode', 'auto');
    foreach (['de', 'en', 'fr', 'ar'] as $locale) {
        File::makeDirectory($this->localeRoot.'/lang/'.$locale);
    }

    $resolver = app(VoxLocaleResolver::class);

    expect($resolver->resolveBaseLocale(['de', 'en', 'ar']))->toBe('en')
        ->and($resolver->managedLocales())->toBe(['en', 'ar', 'de', 'fr']);
});

it('puts an explicit base first and sorts configured locales', function (): void {
    config()->set('app.locale', 'en');
    config()->set('vox.translate.base_locale', 'fr');
    config()->set('vox.translate.locales', ['mode' => 'configured', 'values' => ['de', 'en', 'ar', 'fr']]);

    $resolver = app(VoxLocaleResolver::class);

    expect($resolver->resolveBaseLocale(['de', 'en']))->toBe('fr')
        ->and($resolver->managedLocales())->toBe(['fr', 'ar', 'de', 'en']);
});

it('includes the application base when it is absent from the locale list', function (): void {
    config()->set('app.locale', 'en');
    config()->set('vox.translate.base_locale', 'auto');
    config()->set('vox.translate.locales', ['mode' => 'configured', 'values' => ['fr', 'de']]);

    expect(app(VoxLocaleResolver::class)->managedLocales())->toBe(['en', 'de', 'fr']);
});

it('discovers auto locales from language directories and JSON files but not vendor translations', function (): void {
    config()->set('app.locale', 'en');
    config()->set('vox.translate.base_locale', 'auto');
    config()->set('vox.translate.locales.mode', 'auto');
    File::makeDirectory($this->localeRoot.'/lang/en');
    File::put($this->localeRoot.'/lang/fr.json', '{}');
    File::put($this->localeRoot.'/lang/php_de.json', '{}');
    File::makeDirectory($this->localeRoot.'/lang/vendor/package/es', 0755, true);
    File::makeDirectory($this->localeRoot.'/runtime');
    File::put($this->localeRoot.'/runtime/ro.json', '{}');

    $resolver = app(VoxLocaleResolver::class);

    expect($resolver->sourceLocales())->toBe(['en', 'fr'])
        ->and($resolver->publishedLocales())->toBe(['en', 'fr', 'ro']);
});

it('publishes only declared locales in configured mode while managing locales added through Vox', function (): void {
    config()->set('app.locale', 'en');
    config()->set('vox.translate.base_locale', 'auto');
    config()->set('vox.translate.locales', ['mode' => 'configured', 'values' => ['fr']]);
    File::makeDirectory($this->localeRoot.'/lang/de');
    File::makeDirectory($this->localeRoot.'/runtime');
    File::put($this->localeRoot.'/runtime/ro.json', '{}');
    app(VoxSettingsRepository::class)->save(['provisioned_locales' => ['it']]);

    $resolver = app(VoxLocaleResolver::class);

    expect($resolver->publishedLocales())->toBe(['en', 'fr'])
        ->and($resolver->managedLocales())->toBe(['en', 'fr', 'it'])
        ->and($resolver->isDeclared('it'))->toBeFalse()
        ->and($resolver->unprovisionedLocales())->toBe(['fr']);
});

it('resolves published locales without the Vox database', function (string $mode): void {
    config()->set('app.locale', 'en');
    config()->set('vox.translate.locales', ['mode' => $mode, 'values' => ['fr']]);
    File::makeDirectory($this->localeRoot.'/lang/fr');
    $connection = config('vox.database.connection');
    DB::purge($connection);
    config()->set("database.connections.{$connection}.driver", 'unavailable');

    expect(app(VoxLocaleResolver::class)->publishedLocales())->toBe(['en', 'fr']);
})->with(['auto', 'configured']);

it('uses locales from a registered callback in callback mode', function (): void {
    config()->set('app.locale', 'en');
    config()->set('vox.translate.base_locale', 'auto');
    config()->set('vox.translate.locales.mode', 'callback');
    File::makeDirectory($this->localeRoot.'/lang/de');
    LaravelVox::resolveLocalesUsing(fn (): array => ['fr', ' pt_BR ', 'fr']);

    expect(app(VoxLocaleResolver::class)->publishedLocales())->toBe(['en', 'fr', 'pt_BR']);
});

it('falls back to auto discovery in callback mode without a registered callback', function (): void {
    config()->set('app.locale', 'en');
    config()->set('vox.translate.locales.mode', 'callback');
    File::makeDirectory($this->localeRoot.'/lang/de');

    expect(app(VoxLocaleResolver::class)->publishedLocales())->toBe(['en', 'de']);
});

it('ignores a registered callback outside callback mode', function (): void {
    config()->set('app.locale', 'en');
    config()->set('vox.translate.locales.mode', 'auto');
    File::makeDirectory($this->localeRoot.'/lang/de');
    LaravelVox::resolveLocalesUsing(fn (): array => ['fr']);

    expect(app(VoxLocaleResolver::class)->publishedLocales())->toBe(['en', 'de']);
});

it('rejects a locale callback that does not return locale codes', function (mixed $locales): void {
    config()->set('vox.translate.locales.mode', 'callback');
    LaravelVox::resolveLocalesUsing(fn (): mixed => $locales);

    app(VoxLocaleResolver::class)->publishedLocales();
})->with([['en'], [[1]]])->throws(UnexpectedValueException::class);
