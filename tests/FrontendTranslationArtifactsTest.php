<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Translation\FrontendTranslationArtifacts;
use KeypointSolutions\LaravelVox\Translation\TranslationFileRepository;

beforeEach(function (): void {
    $this->frontendLangPath = base_path('tests/.tmp/frontend-lang-'.Str::uuid());
    $this->frontendRuntimePath = base_path('tests/.tmp/frontend-runtime-'.Str::uuid());
    File::makeDirectory($this->frontendLangPath, 0755, true);
    File::makeDirectory($this->frontendRuntimePath, 0755, true);

    config()->set('vox.paths.lang', $this->frontendLangPath);
    config()->set('vox.frontend.groups', ['frontend']);
    config()->set('vox.frontend.runtime.path', $this->frontendRuntimePath);
    config()->set('vox.translate.locales', ['en', 'fr']);
});

afterEach(function (): void {
    File::deleteDirectory($this->frontendLangPath);
    File::deleteDirectory($this->frontendRuntimePath);
});

it('publishes filtered PHP and JSON translations as one artifact per locale', function (): void {
    $files = app(TranslationFileRepository::class);

    foreach (['en', 'fr'] as $locale) {
        $files->saveGroup($locale, 'frontend', [
            'heading' => $locale === 'en' ? 'Welcome' : 'Bienvenue',
            'nested' => ['button' => $locale === 'en' ? 'Save' : 'Enregistrer'],
        ]);
        $files->saveGroup($locale, 'backend', ['secret' => 'Backend only']);
        $files->saveJson($locale, [
            'JSON message' => $locale === 'en' ? 'From JSON' : 'Depuis JSON',
        ]);
        $files->saveJson($locale, [
            'Package message' => $locale === 'en' ? 'From package' : 'Du paquet',
        ], 'demo-package');
    }

    File::put($this->frontendRuntimePath.'/stale.json', '{}');

    $published = app(FrontendTranslationArtifacts::class)->publish();
    app(FrontendTranslationArtifacts::class)->publish();
    $english = json_decode(File::get($this->frontendRuntimePath.'/en.json'), true);

    expect($published)->toBe([
        $this->frontendRuntimePath.'/en.json',
        $this->frontendRuntimePath.'/fr.json',
    ])->and($english)->toBe([
        'JSON message' => 'From JSON',
        'demo-package::Package message' => 'From package',
        'frontend.heading' => 'Welcome',
        'frontend.nested.button' => 'Save',
    ])->and($english)->not->toHaveKey('backend.secret')
        ->and(File::exists($this->frontendRuntimePath.'/stale.json'))->toBeFalse();
});
