<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use KeypointSolutions\LaravelVox\Models\VoxSetting;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Models\VoxTranslationRule;
use KeypointSolutions\LaravelVox\Support\VoxArchive;
use KeypointSolutions\LaravelVox\Support\VoxDynamicKeyRegistry;
use KeypointSolutions\LaravelVox\Support\VoxLocaleResolver;
use KeypointSolutions\LaravelVox\Translation\RemoteTranslationSnapshot;
use KeypointSolutions\LaravelVox\Translation\TranslationCheckpoints;
use KeypointSolutions\LaravelVox\Translation\TranslationFallbackRules;
use KeypointSolutions\LaravelVox\Translation\TranslationFileRepository;
use KeypointSolutions\LaravelVox\Translation\TranslationFileUpdater;
use KeypointSolutions\LaravelVox\Translation\TranslationPublisher;
use KeypointSolutions\LaravelVox\Translation\TranslationSyncer;

beforeEach(function (): void {
    $this->withoutVite();
    $this->withoutVoxCsrfMiddleware();
    app()->detectEnvironment(fn () => 'local');
    $this->root = base_path('tests/.tmp/fallback-'.Str::uuid());
    File::ensureDirectoryExists($this->root.'/lang');
    config()->set([
        'vox.system.bypass_auth_in_local' => true,
        'vox.translate.locales' => ['mode' => 'configured', 'values' => ['en', 'ro']],
        'vox.translate.base_locale' => 'en',
        'vox.paths.lang' => $this->root.'/lang',
        'vox.parse.paths' => [],
        'vox.frontend.manifest' => $this->root.'/frontend.json',
        'vox.dynamic_keys.manifest' => $this->root.'/dynamic.json',
        'vox.frontend.runtime.path' => $this->root.'/runtime',
        'vox.frontend.groups' => ['mode' => 'configured', 'values' => ['billing']],
    ]);
    $this->files = app(TranslationFileRepository::class);
    $this->rules = app(TranslationFallbackRules::class);
    $this->key = VoxTranslation::factory()->approved()->create(['group' => 'billing', 'key' => 'pay']);
    foreach (['en' => 'Pay now', 'ro' => 'Plătește'] as $locale => $value) {
        $this->key->values()->create(['locale' => $locale, 'value' => $value, 'file_value' => $value, 'is_approved' => true, 'is_pending_publish' => false]);
        $this->files->saveGroup($locale, 'billing', ['pay' => $value]);
    }
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

it('resolves key then group then locale and protects the base language', function (): void {
    $this->rules->save('ro', 'locale', 'default');
    $this->rules->save('ro', 'group', 'translated', 'billing');
    $this->rules->save('ro', 'key', 'default', 'billing', 'pay');
    expect($this->rules->mode('ro', 'other', 'new'))->toBe('default')
        ->and($this->rules->mode('ro', 'billing', 'other'))->toBe('translated')
        ->and($this->rules->mode('ro', 'billing', 'pay'))->toBe('default')
        ->and($this->rules->mode('ro', 'billing', 'pay', true))->toBe('translated');
    $this->post('/vox/manage/fallback', ['locale' => 'en', 'scope' => 'locale', 'mode' => 'default'])->assertSessionHasErrors('locale');
});

it('stages rules and publishes resolved files while preserving drafts and local wording', function (): void {
    config()->set('vox.frontend.runtime.enabled', true);
    $local = $this->key->values()->where('locale', 'ro')->firstOrFail();
    $local->saveDraft('Schiță');
    $this->post('/vox/manage/fallback', ['locale' => 'ro', 'scope' => 'key', 'translation_id' => $this->key->id, 'mode' => 'default'])->assertSessionHasNoErrors();
    expect($this->files->loadGroup('ro', 'billing')['pay'])->toBe('Plătește');
    app(TranslationPublisher::class)->publish();
    expect($this->files->loadGroup('ro', 'billing')['pay'])->toBe('Pay now')
        ->and($local->fresh()->value)->toBe('Schiță')
        ->and($local->fresh()->file_value)->toBe('Plătește')
        ->and(json_decode(File::get($this->root.'/runtime/ro.json'), true)['billing.pay'])->toBe('Pay now');
    app(TranslationSyncer::class)->sync(['en', 'ro'], []);
    expect($local->fresh()->file_value)->toBe('Plătește')->and($local->fresh()->value)->toBe('Schiță');
    $this->rules->save('ro', 'key', 'translated', 'billing', 'pay');
    app(TranslationPublisher::class)->publish();
    expect($this->files->loadGroup('ro', 'billing')['pay'])->toBe('Plătește');
});

it('refreshes dependent fallback files on selective default publication without publishing pending rules', function (): void {
    $this->rules->save('ro', 'locale', 'default');
    app(TranslationPublisher::class)->publish();
    $base = $this->key->values()->where('locale', 'en')->firstOrFail();
    $base->saveDraft('Pay today', true);
    $this->rules->save('ro', 'locale', 'translated');
    app(TranslationPublisher::class)->publish([$base->id]);
    expect($this->files->loadGroup('ro', 'billing')['pay'])->toBe('Pay today')
        ->and(VoxTranslationRule::query()->sole()->published_mode)->toBe('default');
});

it('parses new inherited keys without flags and does not apply draft rules', function (): void {
    $this->rules->save('ro', 'locale', 'default');
    $scan = ['billing.pay' => ['group' => 'billing', 'key' => 'pay'], 'billing.new' => ['group' => 'billing', 'key' => 'new']];
    $this->files->saveGroup('en', 'billing', ['pay' => 'Pay now', 'new' => 'New']);
    $updater = new TranslationFileUpdater($this->files, app(VoxDynamicKeyRegistry::class));
    $updater->updateFromScan($scan, ['en', 'ro'], 'en');
    expect($this->files->loadGroup('ro', 'billing')['new'])->toBe('🚩New');
    app(TranslationPublisher::class)->publish();
    $this->files->saveGroup('ro', 'billing', ['pay' => 'Pay now']);
    $updater->updateFromScan($scan, ['en', 'ro'], 'en');
    expect($this->files->loadGroup('ro', 'billing')['new'])->toBe('New');
});

it('excludes intentional fallback from missing filters and reports its state', function (): void {
    $this->key->values()->where('locale', 'ro')->update(['value' => '🚩Pay now']);
    $this->rules->save('ro', 'locale', 'default');
    $this->get('/vox/manage?status=missing')->assertInertia(fn (AssertableInertia $page) => $page->where('translations.total', 0));
    $this->get('/vox/manage')->assertInertia(fn (AssertableInertia $page) => $page->where('translations.data.0.has_missing_values', false)->where('translations.data.0.fallback.ro.mode', 'default'));
    $this->rules->save('ro', 'key', 'translated', 'billing', 'pay');
    $this->get('/vox/manage?status=missing')->assertInertia(fn (AssertableInertia $page) => $page->where('translations.total', 1));
});

it('creates a fallback language without generating files until publication', function (): void {
    $this->post('/vox/sync/locales', ['locale' => 'de', 'use_default' => true, 'auto_translate' => true])->assertSessionHasNoErrors();
    expect(File::exists($this->root.'/lang/de/billing.php'))->toBeFalse();
    app(TranslationPublisher::class)->publish();
    expect($this->files->loadGroup('de', 'billing')['pay'])->toBe('Pay now');
});

it('refuses missing defaults and rolls back published rules and files', function (): void {
    $this->key->values()->where('locale', 'en')->update(['value' => '', 'file_value' => '']);
    $this->rules->save('ro', 'locale', 'default');
    expect(fn () => app(TranslationPublisher::class)->publish())->toThrow(RuntimeException::class, 'Default translation missing');
    expect(VoxTranslationRule::query()->sole()->published_mode)->toBe('inherit')
        ->and($this->files->loadGroup('ro', 'billing')['pay'])->toBe('Plătește');
});

it('exports and imports published fallback intent with translation archives', function (): void {
    $this->rules->save('ro', 'locale', 'default');
    app(TranslationPublisher::class)->publish();
    $archive = app(VoxArchive::class)->createLangArchive($this->root.'/lang');
    try {
        app(VoxArchive::class)->extractArchive($archive, $this->root.'/import');
        $manifest = File::get($this->root.'/import/'.TranslationFallbackRules::MANIFEST);
        expect(TranslationFallbackRules::validateManifest($manifest)[0]['published_mode'])->toBe('default');
        VoxTranslationRule::query()->delete();
        $this->rules->importManifest($this->root.'/import');
        expect($this->rules->mode('ro', 'billing', 'pay', true))->toBe('default');
    } finally {
        File::delete($archive);
    }
});

it('keeps newly provisioned fallback languages unpublished during parse and sync', function (): void {
    $this->post('/vox/sync/locales', ['locale' => 'de', 'use_default' => true])->assertSessionHasNoErrors();
    $scan = ['billing.pay' => ['group' => 'billing', 'key' => 'pay']];
    (new TranslationFileUpdater($this->files, app(VoxDynamicKeyRegistry::class)))->updateFromScan($scan, ['en', 'ro', 'de'], 'en');
    app(TranslationSyncer::class)->sync(['en', 'ro', 'de'], $scan);
    app(TranslationPublisher::class)->publishTo($this->root.'/lang', overridesOnly: true);
    expect(File::exists($this->root.'/lang/de/billing.php'))->toBeFalse();
});

it('preserves JSON and vendor key identities when resolving fallback', function (): void {
    foreach (['Hello', 'package::Hello'] as $key) {
        $translation = VoxTranslation::factory()->json()->approved()->create(['key' => $key]);
        foreach (['en' => 'Hello', 'ro' => 'Salut'] as $locale => $value) {
            $translation->values()->create(['locale' => $locale, 'value' => $value, 'file_value' => $value, 'is_approved' => true]);
        }
    }
    $this->rules->save('ro', 'group', 'default', 'json');
    $this->rules->save('ro', 'key', 'translated', 'json', 'Hello');
    app(TranslationPublisher::class)->publish();
    expect($this->files->loadJson('ro')['Hello'])->toBe('Salut')
        ->and($this->files->loadJson('ro', 'package')['Hello'])->toBe('Hello');
});

it('skips fallback keys during forced AI translation', function (): void {
    config()->set('vox.translate.driver', 'null');
    $this->rules->save('ro', 'locale', 'default');
    $this->artisan('vox:translate', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
    expect($this->files->loadGroup('ro', 'billing')['pay'])->toBe('Plătește');
});

it('restores rule choices and generated wording together from checkpoints', function (): void {
    $checkpoints = app(TranslationCheckpoints::class);
    $marker = $checkpoints->create('Before fallback');
    $checkpoints->run('manage.fallback', fn () => $this->rules->save('ro', 'locale', 'default'));
    $checkpoints->run('publish.store', fn () => app(TranslationPublisher::class)->publish());
    $checkpoints->restore($marker);
    expect($this->rules->mode('ro', 'billing', 'pay', true))->toBe('translated')
        ->and($this->files->loadGroup('ro', 'billing')['pay'])->toBe('Plătește')
        ->and(VoxTranslationRule::query()->count())->toBe(0);
});

it('does not accept arbitrary model attributes from fallback manifests', function (): void {
    File::put($this->root.'/lang/'.TranslationFallbackRules::MANIFEST, json_encode(['version' => 1, 'rules' => [[
        'locale' => 'ro', 'scope' => 'locale', 'published_mode' => 'default', 'mode' => 'translated', 'id' => 999,
    ]]], JSON_THROW_ON_ERROR));
    $this->rules->importManifest($this->root.'/lang');
    expect(VoxTranslationRule::query()->sole()->mode)->toBe('default')
        ->and(VoxTranslationRule::query()->sole()->id)->not->toBe(999);
});

it('marks own wording missing when leaving fallback without a saved translation', function (): void {
    $this->key->values()->where('locale', 'ro')->delete();
    $this->rules->save('ro', 'locale', 'default');
    app(TranslationPublisher::class)->publish();
    $this->rules->save('ro', 'locale', 'translated');
    app(TranslationPublisher::class)->publish();
    expect($this->files->loadGroup('ro', 'billing')['pay'])->toBe('🚩Pay now');
});

it('rejects unauthenticated fallback changes', function (): void {
    config()->set('vox.system.bypass_auth_in_local', false);
    $this->post('/vox/manage/fallback', ['locale' => 'ro', 'scope' => 'locale', 'mode' => 'default'])->assertForbidden();
    expect(VoxTranslationRule::query()->count())->toBe(0);
});

it('exports effective published wording and ignores metadata in archive snapshots', function (): void {
    $this->rules->save('ro', 'locale', 'default');
    app(TranslationPublisher::class)->publish();
    $snapshot = app(RemoteTranslationSnapshot::class);
    $export = collect($snapshot->export()['values'])->firstWhere('locale', 'ro');
    expect($export['value'])->toBe('Pay now')
        ->and($snapshot->fromDirectory($this->root.'/lang'))->toHaveCount(2);
});

it('discovers published manifest languages after transfer without a configured locale entry', function (): void {
    $this->post('/vox/sync/locales', ['locale' => 'fi', 'use_default' => true])->assertSessionHasNoErrors();
    app(TranslationPublisher::class)->publish();
    VoxSetting::query()->where('key', 'provisioned_locales')->delete();
    $resolver = app(VoxLocaleResolver::class);
    expect($resolver->resolveLocales())->toContain('fi')->and($resolver->resolveFileLocales())->toContain('fi');
    $this->rules->save('fi', 'locale', 'inherit');
    app(TranslationPublisher::class)->publish();
    expect($resolver->resolveFileLocales())->toContain('fi');
});
