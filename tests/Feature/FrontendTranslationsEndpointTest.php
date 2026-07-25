<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->runtimeTranslationPath = base_path('tests/.tmp/runtime-endpoint-'.Str::uuid());
    File::makeDirectory($this->runtimeTranslationPath, 0755, true);

    config()->set('vox.frontend.runtime.enabled', true);
    config()->set('vox.frontend.runtime.path', $this->runtimeTranslationPath);
    config()->set('vox.translate.locales', ['en', 'fr']);
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

it('does not expose artifacts while runtime delivery is disabled', function (): void {
    config()->set('vox.frontend.runtime.enabled', false);

    $this->get('/vox/translations/en')->assertNotFound();
});
