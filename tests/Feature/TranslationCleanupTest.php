<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use KeypointSolutions\LaravelVox\Models\VoxAudit;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;
use KeypointSolutions\LaravelVox\Support\VoxDynamicKeyRegistry;
use KeypointSolutions\LaravelVox\Support\VoxSettingsRepository;
use KeypointSolutions\LaravelVox\Translation\TranslationDatabaseSynchronizer;
use KeypointSolutions\LaravelVox\Translation\TranslationFileRepository;
use KeypointSolutions\LaravelVox\Translation\TranslationPublisher;

beforeEach(function (): void {
    $this->withoutVite();
    $this->withoutVoxCsrfMiddleware();
    app()->detectEnvironment(fn () => 'local');
    config()->set('vox.system.bypass_auth_in_local', true);
    prepareVoxFixtures();
    config()->set('vox.frontend.runtime.enabled', false);
    config()->set('vox.frontend.runtime.path', $this->fixtureRoot.'/runtime');
});

function cleanupKeys($test, array $ids, string $action = 'delete')
{
    return $test->post('/vox/manage/translations/cleanup', ['ids' => $ids, 'action' => $action, 'confirmation' => 'CONFIRM']);
}

it('deletes orphans and their values with an audit but leaves published files untouched', function (): void {
    $row = VoxTranslation::factory()->orphan()->withValues(['en' => 'Old'])->create(['group' => 'old', 'key' => 'unused']);
    $before = File::get(config('vox.paths.lang').'/en/messages.php');
    cleanupKeys($this, [$row->id])->assertRedirect()->assertSessionHasNoErrors();
    expect($row->fresh()->is_pending_delete)->toBeTrue()
        ->and(File::get(config('vox.paths.lang').'/en/messages.php'))->toBe($before)
        ->and(VoxAudit::where('action', 'translations.delete')->first()->context['values_count'])->toBe(1);
});

it('allows deleting published keys in a mixed batch', function (): void {
    $old = VoxTranslation::factory()->orphan()->create(['group' => 'old', 'key' => 'unused']);
    $published = VoxTranslation::factory()->orphan()->create(['group' => 'messages', 'key' => 'fresh']);
    File::put(config('vox.paths.lang').'/en/messages.php', "<?php return ['fresh' => 'Published'];");
    cleanupKeys($this, [$old->id, $published->id])->assertSessionHasNoErrors();
    expect($old->fresh()->is_pending_delete)->toBeTrue()->and($published->fresh()->is_pending_delete)->toBeTrue();
    expect($old->fresh())->not->toBeNull()->and($published->fresh())->not->toBeNull();
});

it('allows deleting dynamic keys and keys protected by retention rules', function (): void {
    config()->set('vox.retained_keys', ['custom.*']);
    $dynamic = VoxTranslation::factory()->create(['group' => 'custom', 'key' => 'new', 'source' => 'dynamic']);
    cleanupKeys($this, [$dynamic->id])->assertRedirect()->assertSessionHasNoErrors();
    $orphan = VoxTranslation::factory()->orphan()->create(['group' => 'custom', 'key' => 'retained']);
    cleanupKeys($this, [$orphan->id])->assertSessionHasNoErrors();
});

it('protects pending deletions during sync and permits cancellation', function (): void {
    $path = config('vox.paths.lang').'/en/messages.php';
    File::put($path, "<?php return ['hello' => 'Published'];");
    $row = VoxTranslation::factory()->approved()->withValues(['en' => 'Draft', 'fr' => 'Brouillon'])->create(['group' => 'messages', 'key' => 'hello']);
    cleanupKeys($this, [$row->id], 'delete')->assertRedirect()->assertSessionHasNoErrors();
    app(TranslationDatabaseSynchronizer::class)->sync();
    expect($row->fresh()->values->firstWhere('locale', 'en')->value)->toBe('Draft');
    expect((require $path)['hello'])->toBe('Published');
    cleanupKeys($this, [$row->id], 'restore')->assertRedirect()->assertSessionHasNoErrors();
    app(TranslationDatabaseSynchronizer::class)->sync();
    expect($row->fresh()->is_pending_delete)->toBeFalse()
        ->and($row->fresh()->values->firstWhere('locale', 'en')->value)->toBe('Draft')
        ->and($row->fresh()->values->firstWhere('locale', 'en')->file_value)->toBe('Published');
});

it('requires explicit confirmation and authorization', function (): void {
    $row = VoxTranslation::factory()->orphan()->create();
    $this->post('/vox/manage/translations/cleanup', ['ids' => [$row->id], 'action' => 'delete'])->assertSessionHasErrors('confirmation');
    config()->set('vox.system.bypass_auth_in_local', false);
    cleanupKeys($this, [$row->id])->assertForbidden();
    expect($row->fresh())->not->toBeNull();
});

it('uses retained keys without reading the removed legacy patterns config', function (): void {
    config()->set('vox.retained_keys', ['keep.*']);
    config()->set('vox.dynamic_keys.patterns', ['legacy.*']);
    $registry = app(VoxDynamicKeyRegistry::class);
    expect($registry->match('thing', 'keep')['sources'])->toBe(['retained-config'])
        ->and($registry->match('thing', 'legacy'))->toBeNull()
        ->and(app(VoxSettingsRepository::class)->configuredDynamicKeyPatterns())->toBe(['keep.*']);
});

it('allows deleting directly referenced keys', function (): void {
    $row = VoxTranslation::factory()->orphan()->create(['group' => 'old', 'key' => 'used']);
    $path = config('vox.paths.lang').'/../app/CleanupUsage.php';
    File::put($path, "<?php __('old.used');");
    cleanupKeys($this, [$row->id])->assertSessionHasNoErrors();
    expect($row->fresh())->not->toBeNull();
});

it('preserves pending deletion file values during parse even when obsolete removal is enabled', function (): void {
    config()->set('vox.parse.obsolete', 'discard');
    File::put(config('vox.paths.lang').'/en/messages.php', "<?php return ['hello' => 'Hello', 'ignored' => 'Keep'];");
    $row = VoxTranslation::factory()->create(['group' => 'messages', 'key' => 'ignored', 'is_pending_delete' => true]);
    app(TranslationDatabaseSynchronizer::class)->sync(true);
    expect((require config('vox.paths.lang').'/en/messages.php')['ignored'])->toBe('Keep')
        ->and($row->fresh()->is_pending_delete)->toBeTrue();
});

it('deletes published dynamic phrases across PHP JSON namespaces and runtime catalogues', function (): void {
    config()->set('vox.retained_keys', ['custom.*', 'vendorname::custom.*', 'Old phrase*']);
    $files = app(TranslationFileRepository::class);
    $rows = collect();
    foreach ([['custom', 'roles.Nurse'], ['vendorname::custom', 'roles.Nurse'], ['json', 'Old phrase']] as [$group, $key]) {
        $rows->push(VoxTranslation::factory()->create(['group' => $group, 'key' => $key, 'source' => 'dynamic']));
    }
    foreach (['en', 'fr', 'ar'] as $locale) {
        $files->saveGroup($locale, 'custom', ['roles' => ['Nurse' => 'Nurse', 'Doctor' => 'Doctor'], 'keep' => 'Keep']);
        $files->saveGroup($locale, 'vendorname::custom', ['roles.Nurse' => 'Nurse', 'keep' => 'Keep']);
        $files->saveJson($locale, ['Old phrase' => 'Old', 'Keep phrase' => 'Keep']);
    }
    File::ensureDirectoryExists(config('vox.frontend.runtime.path'));
    $runtime = config('vox.frontend.runtime.path').'/en.json';
    File::put($runtime, json_encode(['custom.roles.Nurse' => 'Nurse', 'keep' => 'Keep']));
    cleanupKeys($this, $rows->pluck('id')->all())->assertRedirect()->assertSessionHasNoErrors();
    expect($rows->first()->fresh()->is_pending_delete)->toBeTrue();
    expect(File::get($files->groupPath('en', 'custom')))->toContain("'Nurse'");
    app(TranslationDatabaseSynchronizer::class)->sync();
    expect($rows->first()->fresh()->is_pending_delete)->toBeTrue();
    app(TranslationPublisher::class)->publish();
    foreach (['en', 'fr', 'ar'] as $locale) {
        expect(File::get($files->groupPath($locale, 'custom')))->not->toContain("'Nurse'");
        expect($files->loadGroup($locale, 'custom'))->toBe(['keep' => 'Keep', 'roles' => ['Doctor' => 'Doctor']])
            ->and($files->loadGroup($locale, 'vendorname::custom'))->toBe(['keep' => 'Keep'])
            ->and($files->loadJson($locale))->toBe(['Keep phrase' => 'Keep']);
    }
    expect(json_decode(File::get($runtime), true))->toBe(['keep' => 'Keep']);
    expect(VoxTranslation::whereIn('id', $rows->pluck('id'))->count())->toBe(0);
});

it('keeps files and rows unchanged when scheduling deletion fails', function (): void {
    config()->set('vox.retained_keys', ['custom.*']);
    $row = VoxTranslation::factory()->create(['group' => 'custom', 'key' => 'unused', 'source' => 'dynamic']);
    $path = config('vox.paths.lang').'/en/custom.php';
    File::put($path, "<?php return ['unused' => 'Original', 'keep' => 'Keep'];");
    $before = File::get($path);
    $audit = Mockery::mock(VoxAuditLogger::class);
    $audit->shouldReceive('record')->andThrow(new RuntimeException('Audit failed'));
    $this->app->instance(VoxAuditLogger::class, $audit);
    $this->withoutExceptionHandling();
    expect(fn () => cleanupKeys($this, [$row->id]))->toThrow(RuntimeException::class, 'Audit failed');
    expect(File::get($path))->toBe($before)->and($row->fresh())->not->toBeNull();
});

it('can cancel a pending deletion before publishing', function (): void {
    config()->set('vox.retained_keys', ['custom.*']);
    $row = VoxTranslation::factory()->create(['group' => 'custom', 'key' => 'unused', 'source' => 'dynamic']);
    cleanupKeys($this, [$row->id])->assertSessionHasNoErrors();
    $this->get('/vox/manage?status=pending-deletion')->assertInertia(fn ($page) => $page->has('translations.data', 1));
    cleanupKeys($this, [$row->id], 'restore')->assertSessionHasNoErrors();
    expect($row->fresh()->is_pending_delete)->toBeFalse();
});

it('publishes deletion of used keys and removes emptied PHP JSON namespace and runtime files', function (): void {
    $files = app(TranslationFileRepository::class);
    $paths = [];
    $rows = collect();
    foreach ([['sole', 'nested.phrase'], ['vendorname::sole', 'phrase'], ['json', 'Sole phrase'], ['json', 'vendorname::Sole phrase']] as [$group, $key]) {
        $rows->push(VoxTranslation::factory()->withValues(['en' => 'Original', 'fr' => 'Original FR'])->create(['group' => $group, 'key' => $key]));
    }
    foreach (['en', 'fr'] as $locale) {
        $files->saveGroup($locale, 'sole', ['nested' => ['phrase' => 'Original']]);
        $files->saveGroup($locale, 'vendorname::sole', ['phrase' => 'Original']);
        $files->saveJson($locale, ['Sole phrase' => 'Original']);
        $files->saveJson($locale, ['Sole phrase' => 'Original'], 'vendorname');
        array_push($paths, $files->groupPath($locale, 'sole'), $files->groupPath($locale, 'vendorname::sole'), $files->jsonPath($locale), $files->jsonPath($locale, 'vendorname'));
    }
    File::ensureDirectoryExists(config('vox.frontend.runtime.path'));
    $runtime = config('vox.frontend.runtime.path').'/en.json';
    File::put($runtime, '{"sole.nested.phrase":"Original"}');
    $paths[] = $runtime;
    cleanupKeys($this, $rows->pluck('id')->all())->assertSessionHasNoErrors();
    app(TranslationPublisher::class)->publish();
    foreach ($paths as $path) {
        expect(File::exists($path))->toBeFalse();
    }
    expect(VoxTranslation::whereIn('id', $rows->pluck('id'))->count())->toBe(0);
});

it('restores files removed during publishing if a later step fails', function (): void {
    $row = VoxTranslation::factory()->create(['group' => 'sole', 'key' => 'phrase']);
    $files = app(TranslationFileRepository::class);
    $files->saveGroup('en', 'sole', ['phrase' => 'Original']);
    $path = $files->groupPath('en', 'sole');
    chmod($path, 0644);
    $before = File::get($path);
    File::ensureDirectoryExists(config('vox.frontend.runtime.path'));
    File::put(config('vox.frontend.runtime.path').'/en.json', 'invalid JSON');
    cleanupKeys($this, [$row->id])->assertSessionHasNoErrors();
    expect(fn () => app(TranslationPublisher::class)->publish())->toThrow(JsonException::class);
    clearstatcache(true, $path);
    expect(File::get($path))->toBe($before)->and(fileperms($path) & 0777)->toBe(0644)
        ->and($row->fresh()->is_pending_delete)->toBeTrue();
});

it('converts old ignored rows to pending deletion without publishing their values', function (): void {
    $migration = require __DIR__.'/../../database/migrations/2026_09_15_000002_replace_ignored_translations_with_pending_deletion.php';
    $migration->down();
    $row = VoxTranslation::factory()->withValues(['en' => 'Keep until Publish'])->create();
    DB::connection($row->getConnectionName())->table('vox_translations')->where('id', $row->id)->update(['is_ignored' => true]);
    $migration->up();
    expect($row->fresh()->is_pending_delete)->toBeTrue()
        ->and($row->values()->first()->value)->toBe('Keep until Publish')
        ->and(Schema::connection($row->getConnectionName())->hasColumn('vox_translations', 'is_ignored'))->toBeFalse();
    cleanupKeys($this, [$row->id], 'ignore')->assertSessionHasErrors('action');
});
