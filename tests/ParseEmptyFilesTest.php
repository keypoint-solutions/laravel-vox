<?php

use Illuminate\Support\Facades\File;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Translation\TranslationFileUpdater;
use KeypointSolutions\LaravelVox\Translation\TranslationPublisher;

beforeEach(function (): void {
    $root = prepareVoxFixtures();
    config()->set('vox.parse.paths', []);
    config()->set('vox.parse.obsolete', 'discard');
    config()->set('vox.retained_keys', []);
    $this->cleanupLang = $root.'/lang';
});

it('removes abandoned PHP and JSON files including namespaces through parse and sync parse', function (string $command): void {
    foreach (['en', 'fr'] as $locale) {
        File::ensureDirectoryExists($this->cleanupLang.'/vendor/abandoned/'.$locale);
        File::put($this->cleanupLang.'/'.$locale.'/abandoned.php', "<?php return ['nested' => ['old' => 'Old']];");
        File::put($this->cleanupLang.'/vendor/abandoned/'.$locale.'/old.php', "<?php return ['old' => 'Old'];");
        File::put($this->cleanupLang.'/'.$locale.'.json', '{"Old JSON": "Old JSON"}');
        File::put($this->cleanupLang.'/vendor/abandoned/'.$locale.'.json', '{"Old vendor JSON": "Old vendor JSON"}');
    }
    $options = ['--no-interaction' => true];
    if ($command === 'vox:sync') {
        $options['--parse'] = true;
    }
    $this->artisan($command, $options)->assertExitCode(0);
    foreach (['en', 'fr'] as $locale) {
        foreach ([$locale.'/abandoned.php', 'vendor/abandoned/'.$locale.'/old.php', $locale.'.json', 'vendor/abandoned/'.$locale.'.json'] as $path) {
            expect(File::exists($this->cleanupLang.'/'.$path))->toBeFalse();
        }
    }
    $this->artisan($command, $options)->assertExitCode(0);
    expect(File::exists($this->cleanupLang.'/en.json'))->toBeFalse();
})->with(['vox:parse', 'vox:sync']);

it('preserves pending-deletion retained and secondary-locale-only keys in abandoned groups', function (): void {
    config()->set('vox.retained_keys', ['retained.*', 'saved::*']);
    File::ensureDirectoryExists($this->cleanupLang.'/vendor/saved');
    File::put($this->cleanupLang.'/en/retained.php', "<?php return ['keep' => 'Retained'];");
    File::put($this->cleanupLang.'/en/ignored.php', "<?php return ['keep' => 'Ignored'];");
    File::put($this->cleanupLang.'/fr/secondary.php', "<?php return ['keep' => 'French only'];");
    File::put($this->cleanupLang.'/vendor/saved/en.json', '{"Keep": "Retained JSON"}');
    VoxTranslation::factory()->create(['group' => 'ignored', 'key' => 'keep', 'is_pending_delete' => true]);
    $this->artisan('vox:parse', ['--no-interaction' => true])->assertExitCode(0);
    expect((require $this->cleanupLang.'/en/retained.php')['keep'])->toBe('Retained')
        ->and((require $this->cleanupLang.'/en/ignored.php')['keep'])->toBe('Ignored')
        ->and((require $this->cleanupLang.'/fr/secondary.php')['keep'])->toBe('French only')
        ->and(json_decode(File::get($this->cleanupLang.'/vendor/saved/en.json'), true)['Keep'])->toBe('Retained JSON')
        ->and(File::exists($this->cleanupLang.'/en/secondary.php'))->toBeFalse();
    config()->set('vox.parse.keep_orphan_other_locales_keys', false);
    $this->artisan('vox:parse', ['--no-interaction' => true])->assertExitCode(0);
    expect(File::exists($this->cleanupLang.'/fr/secondary.php'))->toBeFalse();
});

it('does not delete abandoned files when obsolete keys are kept or commented', function (string $mode): void {
    config()->set('vox.parse.obsolete', $mode);
    File::put($this->cleanupLang.'/en/abandoned.php', "<?php return ['old' => 'Old'];");
    $this->artisan('vox:parse', ['--no-interaction' => true])->assertExitCode(0);
    expect(File::exists($this->cleanupLang.'/en/abandoned.php'))->toBeTrue();
})->with(['keep', 'comment']);

it('restores deleted files and their permissions when later parsing fails', function (): void {
    $path = $this->cleanupLang.'/en/abandoned.php';
    $contents = "<?php return ['old' => 'Old'];";
    File::put($path, $contents);
    chmod($path, 0644);
    File::put($this->cleanupLang.'/en.json', 'invalid JSON');
    expect(fn () => app(TranslationFileUpdater::class)->updateFromScan([], ['en', 'fr'], 'en'))->toThrow(RuntimeException::class, 'contains invalid JSON');
    clearstatcache(true, $path);
    expect(File::get($path))->toBe($contents)->and(fileperms($path) & 0777)->toBe(0644);
});

it('uses the same configured group format for parsing and publishing', function (string $format): void {
    config()->set('vox.parse.output', $format);
    config()->set('vox.parse.preserve_existing_format', false);
    File::put($this->cleanupLang.'/en/messages.php', "<?php return ['promo' => ['line' => 'Nested'], 'promo.line' => 'Flat'];");
    $scan = ['messages.promo.line' => ['group' => 'messages', 'key' => 'promo.line', 'occurrences' => []]];
    app(TranslationFileUpdater::class)->updateFromScan($scan, ['en'], 'en');
    $parsed = require $this->cleanupLang.'/en/messages.php';
    expect($parsed)->toBe($format === 'flat' ? ['promo.line' => 'Flat'] : ['promo' => ['line' => 'Flat']]);
    $translation = VoxTranslation::factory()->approved()->withValues(['en' => 'Published'])
        ->create(['group' => 'messages', 'key' => 'promo.line']);
    app(TranslationPublisher::class)->publish();
    expect(require $this->cleanupLang.'/en/messages.php')->toBe(
        $format === 'flat' ? ['promo.line' => 'Published'] : ['promo' => ['line' => 'Published']]
    );
    app(TranslationFileUpdater::class)->updateFromScan($scan, ['en'], 'en');
    expect(require $this->cleanupLang.'/en/messages.php')->toBe(
        $format === 'flat' ? ['promo.line' => 'Published'] : ['promo' => ['line' => 'Published']]
    );
})->with(['flat', 'nested']);

it('preserves a single existing representation through parse and publish', function (bool $nested): void {
    config()->set('vox.parse.output', 'flat');
    config()->set('vox.parse.preserve_existing_format', true);
    $original = $nested ? ['promo' => ['line' => 'Original']] : ['promo.line' => 'Original'];
    File::put($this->cleanupLang.'/en/messages.php', '<?php return '.var_export($original, true).';');
    $scan = ['messages.promo.line' => ['group' => 'messages', 'key' => 'promo.line', 'occurrences' => []]];
    app(TranslationFileUpdater::class)->updateFromScan($scan, ['en'], 'en');
    expect(require $this->cleanupLang.'/en/messages.php')->toBe($original);
    VoxTranslation::factory()->approved()->withValues(['en' => 'Published'])
        ->create(['group' => 'messages', 'key' => 'promo.line']);
    app(TranslationPublisher::class)->publish();
    expect(require $this->cleanupLang.'/en/messages.php')->toBe(
        $nested ? ['promo' => ['line' => 'Published']] : ['promo.line' => 'Published']
    );
})->with([false, true]);

it('reports files deleted by parsing', function (): void {
    File::put($this->cleanupLang.'/en/abandoned.php', "<?php return ['old' => 'Old'];");
    $this->artisan('vox:parse', ['--no-interaction' => true])
        ->expectsOutputToContain('Deleted')
        ->assertExitCode(0);
});
