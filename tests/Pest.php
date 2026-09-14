<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Tests\TestCase;

uses(TestCase::class)
    ->beforeEach(function (): void {
        config()->set('vox.retained_keys', []);
    })
    ->afterEach(function (): void {
        if (isset($this->fixtureRoot) && File::isDirectory($this->fixtureRoot)) {
            File::deleteDirectory($this->fixtureRoot);
        }
    })
    ->in(__DIR__);

function prepareVoxFixtures(): string
{
    $fixtureRoot = __DIR__.'/Fixtures';
    $targetRoot = __DIR__.'/.tmp/vox-fixtures-'.Str::uuid();

    File::copyDirectory($fixtureRoot, $targetRoot);

    config()->set('vox.paths.lang', $targetRoot.'/lang');
    config()->set('vox.parse.paths', [
        $targetRoot.'/app',
        $targetRoot.'/resources',
    ]);
    config()->set('vox.parse.exclude', []);
    config()->set('vox.retained_keys', [
        'frontend.dynamicLabels.values.*',
        'frontend.dynamicLabels2.values.*',
    ]);
    config()->set('vox.dynamic_keys.manifest', $targetRoot.'/dynamic.json');
    config()->set('vox.translate.locales.mode', 'configured');
    config()->set('vox.translate.locales.values', ['en', 'fr']);
    config()->set('vox.translate.base_locale', 'en');

    test()->fixtureRoot = $targetRoot;

    return $targetRoot;
}
