<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use KeypointSolutions\LaravelVox\Models\VoxAudit;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
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

function seedManageTranslations(): void
{
    $syncAt = Carbon::now()->subDay();

    VoxAudit::factory()->sync(3)->create(['created_at' => $syncAt]);

    VoxTranslation::factory()
        ->frontend()
        ->withValues(['en' => 'Welcome', 'fr' => 'Bienvenue'])
        ->withOccurrence('resources/views/welcome.blade.php', 12, '<h1>', '</h1>')
        ->withTimestamps(Carbon::now()->subHours(2))
        ->create(['group' => 'frontend', 'key' => 'welcome']);

    VoxTranslation::factory()
        ->withValues(['en' => 'Dashboard', 'fr' => 'Tableau de bord'])
        ->withTimestamps(Carbon::now()->subDays(3), Carbon::now()->subHours(1))
        ->create(['group' => 'backend', 'key' => 'dashboard']);

    VoxTranslation::factory()
        ->json()
        ->approved()
        ->withValues(['en' => 'Welcome JSON', 'fr' => 'Bienvenue JSON'])
        ->withTimestamps(Carbon::now()->subDays(4))
        ->create(['key' => 'Welcome JSON']);
}

/**
 * @return Collection<int, array<string, mixed>>
 */
function manageTranslations(TestResponse $response): Collection
{
    $response->assertOk();

    return collect($response->inertiaPage()['props']['translations']['data']);
}
