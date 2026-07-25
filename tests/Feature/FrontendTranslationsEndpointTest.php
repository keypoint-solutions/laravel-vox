<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->runtimeTranslationPath = base_path('tests/.tmp/runtime-endpoint-'.Str::uuid());
    File::makeDirectory($this->runtimeTranslationPath, 0755, true);

    config()->set('vox.frontend.runtime.enabled', true);
    config()->set('vox.frontend.runtime.path', $this->runtimeTranslationPath);
    config()->set('vox.translate.locales.mode', 'configured');
    config()->set('vox.translate.locales.values', ['en', 'fr']);
    File::put($this->runtimeTranslationPath.'/en.json', '{"frontend.greeting":"Hello"}');
});

afterEach(function (): void {
    File::deleteDirectory($this->runtimeTranslationPath);
});

it('serves only prebuilt configured locale artifacts with cache validation', function (): void {
    $response = $this->get('/vox/translations/en')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json; charset=UTF-8')
        ->assertHeader('Cache-Control', 'no-cache, public')
        ->assertExactJson(['frontend.greeting' => 'Hello']);

    $etag = $response->headers->get('ETag');

    expect($etag)->toBeString()->not->toBe('');

    $this->withHeader('If-None-Match', $etag)
        ->get('/vox/translations/en')
        ->assertStatus(304);

    $this->get('/vox/translations/de')->assertNotFound();
    $this->get('/vox/translations/fr')->assertNotFound();
});

it('exposes the defined locale catalogue and runtime artifact availability', function (): void {
    $response = $this->get('/vox/locales')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('default_locale', 'en')
        ->assertJsonPath('locales.0.code', 'en')
        ->assertJsonPath('locales.0.name', 'English')
        ->assertJsonPath('locales.0.is_default', true)
        ->assertJsonPath('locales.0.has_runtime_translations', true)
        ->assertJsonPath('locales.1.code', 'fr')
        ->assertJsonPath('locales.1.name', 'French')
        ->assertJsonPath('locales.1.has_runtime_translations', false);

    $etag = $response->headers->get('ETag');

    expect($etag)->toBeString()->not->toBe('');

    $this->withHeader('If-None-Match', $etag)
        ->get('/vox/locales')
        ->assertStatus(304);
});

it('does not expose artifacts while runtime delivery is disabled', function (): void {
    config()->set('vox.frontend.runtime.enabled', false);

    $this->get('/vox/translations/en')->assertNotFound();
    $this->get('/vox/locales')->assertNotFound();
});
