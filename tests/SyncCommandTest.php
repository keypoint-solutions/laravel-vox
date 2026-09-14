<?php

use Illuminate\Support\Facades\File;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Models\VoxTranslationOccurrence;
use KeypointSolutions\LaravelVox\Models\VoxTranslationValue;
use KeypointSolutions\LaravelVox\Support\VoxDynamicKeyRegistry;
use KeypointSolutions\LaravelVox\Translation\TranslationFileRepository;
use KeypointSolutions\LaravelVox\Translation\TranslationFileWriter;
use KeypointSolutions\LaravelVox\Translation\TranslationSyncer;

it('syncs translation files into the vox database', function () {
    prepareVoxFixtures();

    $this->artisan('vox:parse', ['--no-interaction' => true])->assertExitCode(0);
    $this->artisan('vox:sync')->assertExitCode(0);

    $translation = VoxTranslation::query()
        ->where('group', 'messages')
        ->where('key', 'hello')
        ->first();

    expect($translation)->not->toBeNull()
        ->and($translation->is_frontend)->toBeTrue();

    $value = VoxTranslationValue::query()
        ->where('translation_id', $translation->id)
        ->where('locale', 'fr')
        ->first();

    expect($value)->not->toBeNull()
        ->and($value->value)->toStartWith('🚩');

    $occurrence = VoxTranslationOccurrence::query()
        ->where('translation_id', $translation->id)
        ->first();

    expect($occurrence)->not->toBeNull();

    $vendorTranslation = VoxTranslation::query()
        ->where('group', 'vendorpkg::messages')
        ->where('key', 'title')
        ->first();

    expect($vendorTranslation)->not->toBeNull();
});

it('refreshes runtime frontend artifacts when local sync is enabled', function (): void {
    $targetRoot = prepareVoxFixtures();
    $runtimePath = $targetRoot.'/frontend-runtime';

    config()->set('vox.frontend.runtime.enabled', true);
    config()->set('vox.frontend.runtime.path', $runtimePath);

    $this->artisan('vox:parse', ['--no-interaction' => true])->assertExitCode(0);
    $this->artisan('vox:sync')->assertExitCode(0);

    $english = json_decode(File::get($runtimePath.'/en.json'), true, flags: JSON_THROW_ON_ERROR);
    $french = json_decode(File::get($runtimePath.'/fr.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($english)
        ->toHaveKey('messages.hello', 'hello')
        ->and($french)
        ->toHaveKey('messages.hello', '🚩hello');
});

it('removes a stale frontend flag when a key is no longer used in frontend code', function (): void {
    $targetRoot = prepareVoxFixtures();
    $translation = VoxTranslation::factory()
        ->frontend()
        ->withValues(['en' => 'Hello', 'fr' => 'Bonjour'])
        ->create(['group' => 'messages', 'key' => 'hello']);

    $repository = new TranslationFileRepository(new TranslationFileWriter);
    $syncer = new TranslationSyncer($repository, app(VoxDynamicKeyRegistry::class));
    $syncer->sync(['en', 'fr'], [
        'messages.hello' => [
            'group' => 'messages',
            'key' => 'hello',
            'is_frontend' => false,
            'source' => '__',
            'occurrences' => [[
                'file' => $targetRoot.'/app/Example.php',
                'line' => 1,
                'before' => null,
                'after' => null,
            ]],
        ],
    ]);

    expect($translation->fresh())
        ->is_frontend->toBeFalse()
        ->source->toBe('__');
});

it('keeps database-only dynamic translations active during sync', function (): void {
    prepareVoxFixtures();
    config()->set('vox.retained_keys', ['enums.user_roles.*']);

    $translation = VoxTranslation::factory()
        ->approved()
        ->withValues(['en' => 'Administrator', 'fr' => 'Administrateur'])
        ->create([
            'group' => 'enums',
            'key' => 'user_roles.admin',
            'source' => 'dynamic',
        ]);

    $this->artisan('vox:sync')->assertExitCode(0);

    expect($translation->fresh())
        ->is_orphan->toBeFalse()
        ->source->toBe('dynamic')
        ->status->toBe('approved');
});

it('keeps an unchanged approved translation approved during sync', function (): void {
    prepareVoxFixtures();
    $translation = VoxTranslation::factory()
        ->approved()
        ->withValues(['en' => 'Hello', 'fr' => '🚩 Hello'])
        ->create(['group' => 'messages', 'key' => 'hello']);

    $this->artisan('vox:sync')->assertExitCode(0);

    expect($translation->fresh()->status)->toBe('approved');
});

it('marks database-only translations as orphans and restores them when they reappear', function (): void {
    prepareVoxFixtures();
    $translation = VoxTranslation::factory()
        ->frontend()
        ->approved()
        ->withValues(['en' => 'Database only', 'fr' => 'Base uniquement'])
        ->withOccurrence('resources/js/Removed.vue')
        ->create(['group' => 'removed', 'key' => 'message']);
    $updatedAt = $translation->updated_at;

    $this->artisan('vox:sync')->assertExitCode(0);

    expect($translation->fresh())
        ->is_orphan->toBeTrue()
        ->is_frontend->toBeFalse()
        ->source->toBeNull()
        ->updated_at->equalTo($updatedAt)->toBeTrue()
        ->and($translation->occurrences()->count())->toBe(0);

    $repository = new TranslationFileRepository(new TranslationFileWriter);
    $repository->saveGroup('en', 'removed', ['message' => 'Database only']);
    $repository->saveGroup('fr', 'removed', ['message' => 'Base uniquement']);

    $this->artisan('vox:sync')->assertExitCode(0);

    expect($translation->fresh()->is_orphan)->toBeFalse();
});
