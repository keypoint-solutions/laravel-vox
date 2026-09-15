<?php

use Illuminate\Support\Facades\File;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Translation\FrontendTranslationArtifacts;
use KeypointSolutions\LaravelVox\Translation\TranslationFileRepository;
use KeypointSolutions\LaravelVox\Translation\TranslationFileUpdater;
use KeypointSolutions\LaravelVox\Translation\TranslationFileValidator;
use KeypointSolutions\LaravelVox\Translation\TranslationPublisher;

it('discovers translates syncs publishes and compiles nested Laravel groups', function (string $group, string $relative): void {
    $root = prepareVoxFixtures();
    config()->set('vox.translate.driver', 'null');
    config()->set('vox.frontend.groups', ['mode' => 'configured', 'values' => ['*']]);
    config()->set('vox.frontend.runtime.path', $root.'/runtime');
    $files = app(TranslationFileRepository::class);
    $files->saveGroup('en', $group, ['welcome' => 'Welcome']);
    $files->saveGroup('fr', $group, ['welcome' => '🚩Welcome']);
    expect($files->groups('en'))->toContain($group);
    $this->artisan('vox:translate', ['--path' => $relative])->assertExitCode(0);
    expect($files->loadGroup('fr', $group))->toBe(['welcome' => 'Welcome']);
    $this->artisan('vox:sync')->assertExitCode(0);
    $translation = VoxTranslation::query()->where('group', $group)->where('key', 'welcome')->firstOrFail();
    $translation->values()->where('locale', 'fr')->firstOrFail()->saveDraft('Bienvenue', true);
    app(TranslationPublisher::class)->publish();
    expect($files->loadGroup('fr', $group))->toBe(['welcome' => 'Bienvenue']);
    app(FrontendTranslationArtifacts::class)->publish();
    expect(json_decode(File::get($root.'/runtime/fr.json'), true))->toHaveKey($group.'.welcome', 'Bienvenue');
    app(TranslationFileValidator::class)->assertTranslationPath($relative);
    $scan = [$group.'.welcome' => ['group' => $group, 'key' => 'welcome', 'occurrences' => []]];
    app(TranslationFileUpdater::class)->updateFromScan($scan, ['en', 'fr'], 'en');
    expect($files->loadGroup('fr', $group))->toBe(['welcome' => 'Bienvenue']);
    config()->set('vox.parse.keep_orphan_other_locales_keys', false);
    app(TranslationFileUpdater::class)->updateFromScan([], ['en', 'fr'], 'en');
    expect(File::exists($files->groupPath('en', $group)))->toBeFalse()
        ->and(File::exists($files->groupPath('fr', $group)))->toBeFalse();
})->with([
    ['admin/messages', 'en/admin/messages.php'],
    ['package::admin/messages', 'vendor/package/en/admin/messages.php'],
]);

it('still rejects traversal within nested translation archive paths', function (string $path): void {
    expect(fn () => app(TranslationFileValidator::class)->assertTranslationPath($path))->toThrow(RuntimeException::class);
})->with(['en/admin/../secrets.php', 'vendor/package/en/../../secrets.php', 'en/admin//messages.php']);

it('parses slash-based references from source code into nested group files', function (): void {
    $root = prepareVoxFixtures();
    File::put($root.'/app/NestedReferences.php', "<?php __('admin/messages.welcome'); __('package::admin/messages.welcome');");
    $this->artisan('vox:sync', ['--parse' => true, '--no-interaction' => true])->assertExitCode(0);
    expect(File::exists($root.'/lang/en/admin/messages.php'))->toBeTrue()
        ->and(File::exists($root.'/lang/vendor/package/en/admin/messages.php'))->toBeTrue()
        ->and(VoxTranslation::query()->where('group', 'admin/messages')->where('key', 'welcome')->exists())->toBeTrue();
});
