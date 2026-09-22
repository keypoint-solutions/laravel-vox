<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;
use KeypointSolutions\LaravelVox\Models\VoxAudit;
use KeypointSolutions\LaravelVox\Models\VoxEnvironment;
use KeypointSolutions\LaravelVox\Models\VoxRemoteTranslation;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Models\VoxTranslationRule;
use KeypointSolutions\LaravelVox\Models\VoxTranslationValue;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;
use KeypointSolutions\LaravelVox\Support\VoxLocaleResolver;
use KeypointSolutions\LaravelVox\Support\VoxSettingsRepository;
use KeypointSolutions\LaravelVox\Translation\FrontendTranslationArtifacts;
use KeypointSolutions\LaravelVox\Translation\LocaleProvisioner;
use KeypointSolutions\LaravelVox\Translation\TranslationCheckpoints;
use KeypointSolutions\LaravelVox\Translation\TranslationFallbackRules;
use KeypointSolutions\LaravelVox\Translation\TranslationFileRepository;

beforeEach(function (): void {
    $this->withoutVite();
    $this->withoutVoxCsrfMiddleware();
    app()->detectEnvironment(fn () => 'local');
    config()->set('vox.system.bypass_auth_in_local', true);
    prepareVoxFixtures();
    config()->set('vox.frontend.runtime.path', $this->fixtureRoot.'/runtime');
    config()->set('vox.translate.locales.mode', 'auto');
    config()->set('vox.translate.base_locale', 'en');
    config()->set('app.locale', 'en');
    config()->set('app.fallback_locale', 'en');
});

it('removes a provisioned language and all its file and database surfaces while preserving other languages', function (): void {
    $files = app(TranslationFileRepository::class);
    $files->saveGroup('en', 'cashier::messages', ['receipt' => 'Receipt']);
    $files->saveJson('en', ['Checkout' => 'Checkout'], 'cashier');
    app(LocaleProvisioner::class)->provision('de');
    $rules = app(TranslationFallbackRules::class);
    $rules->save('de', 'locale', 'default');
    $rules->save('fr', 'locale', 'default');
    $rules->publish();
    $rules->writeManifest($files->langPath());
    $environment = VoxEnvironment::query()->create(['name' => 'Local', 'type' => 'local', 'url' => '', 'secret_key' => '']);
    foreach (['de', 'fr'] as $locale) {
        VoxRemoteTranslation::query()->create(['environment_id' => $environment->id, 'identity' => $locale, 'group' => 'messages', 'key' => 'hello', 'locale' => $locale, 'remote_value' => 'Remote']);
    }
    $keys = VoxTranslation::query()->count();
    $frenchValues = VoxTranslationValue::query()->where('locale', 'fr')->count();
    $english = File::get($files->groupPath('en', 'messages'));
    $runtime = app(FrontendTranslationArtifacts::class)->pathForLocale('de');
    File::ensureDirectoryExists(dirname($runtime));
    File::put($runtime, '{"hello":"Hallo"}');

    $this->from('/vox/sync')->delete('/vox/sync/locales', ['locale' => 'de'])
        ->assertRedirect('/vox/sync')->assertSessionHasNoErrors()
        ->assertInertiaFlash('success', 'Removed German (de).');

    expect(File::exists($files->langPath().'/de'))->toBeFalse()
        ->and(File::exists($files->jsonPath('de')))->toBeFalse()
        ->and(File::exists($files->langPath().'/vendor/cashier/de'))->toBeFalse()
        ->and(File::exists($files->jsonPath('de', 'cashier')))->toBeFalse()
        ->and(File::exists($runtime))->toBeFalse()
        ->and(VoxTranslationValue::query()->where('locale', 'de')->count())->toBe(0)
        ->and(VoxRemoteTranslation::query()->where('locale', 'de')->count())->toBe(0)
        ->and(VoxTranslationRule::query()->where('locale', 'de')->count())->toBe(0)
        ->and(app(VoxSettingsRepository::class)->provisionedLocales())->toBe([])
        ->and(app(VoxLocaleResolver::class)->resolveLocales())->not->toContain('de')
        ->and(app(VoxLocaleResolver::class)->resolveRuntimeLocales())->not->toContain('de')
        ->and(VoxTranslation::query()->count())->toBe($keys)
        ->and(VoxTranslationValue::query()->where('locale', 'fr')->count())->toBe($frenchValues)
        ->and(VoxRemoteTranslation::query()->where('locale', 'fr')->count())->toBe(1)
        ->and(File::get($files->groupPath('en', 'messages')))->toBe($english)
        ->and(array_column(TranslationFallbackRules::validateManifest(File::get($files->langPath().'/vox-fallback.json')), 'locale'))->toBe(['fr'])
        ->and(VoxAudit::query()->where('action', 'locale-removed')->exists())->toBeTrue();
});

it('removes an unpublished default-wording language and allows adding it again', function (): void {
    app(LocaleProvisioner::class)->provision('de', useDefault: true);
    $this->delete('/vox/sync/locales', ['locale' => 'de'])->assertSessionHasNoErrors();
    expect(app(VoxLocaleResolver::class)->resolveLocales())->not->toContain('de');
    app(LocaleProvisioner::class)->provision('de', useDefault: true);
    expect(app(VoxLocaleResolver::class)->resolveLocales())->toContain('de');
});

it('rejects default fallback configured unsupported and unsafe locales', function (string $locale): void {
    app(VoxSettingsRepository::class)->save(['provisioned_locales' => ['en', 'fr', 'ro', 'es']]);
    config()->set('vox.translate.locales.mode', 'configured');
    config()->set('vox.translate.locales.values', ['en', 'fr']);
    config()->set('laravellocalization.supportedLocales', ['ro' => []]);
    config()->set('app.fallback_locale', 'es');
    $this->delete('/vox/sync/locales', ['locale' => $locale])->assertSessionHasErrors('locale');
    expect(app(VoxSettingsRepository::class)->provisionedLocales())->toBe(['en', 'fr', 'ro', 'es']);
})->with(['en', 'fr', 'ro', 'es', 'de', '../en']);

it('requires dashboard authorization', function (): void {
    app(LocaleProvisioner::class)->provision('de', useDefault: true);
    config()->set('vox.system.bypass_auth_in_local', false);
    $this->delete('/vox/sync/locales', ['locale' => 'de'])->assertForbidden();
    expect(app(VoxSettingsRepository::class)->provisionedLocales())->toContain('de');
});

it('rolls files directories rules and values back when removal fails', function (): void {
    app(LocaleProvisioner::class)->provision('de');
    $files = app(TranslationFileRepository::class);
    $rules = app(TranslationFallbackRules::class);
    $rules->save('de', 'locale', 'default');
    $rules->writeManifest($files->langPath());
    $before = File::get($files->groupPath('de', 'messages'));
    $manifest = File::get($files->langPath().'/vox-fallback.json');
    $values = VoxTranslationValue::query()->where('locale', 'de')->count();
    $this->mock(VoxAuditLogger::class)->shouldReceive('record')->with('locale-removed', Mockery::any())->andThrow(new RuntimeException('Audit failed'));

    $this->delete('/vox/sync/locales', ['locale' => 'de'])->assertSessionHasErrors('locale');
    expect(File::get($files->groupPath('de', 'messages')))->toBe($before)
        ->and(File::get($files->langPath().'/vox-fallback.json'))->toBe($manifest)
        ->and(VoxTranslationValue::query()->where('locale', 'de')->count())->toBe($values)
        ->and(app(VoxSettingsRepository::class)->provisionedLocales())->toContain('de')
        ->and($rules->selection('de', 'locale'))->toBe('default');
});

it('can restore a removed language from its automatic checkpoint', function (): void {
    app(LocaleProvisioner::class)->provision('de');
    $path = app(TranslationFileRepository::class)->groupPath('de', 'messages');
    $before = File::get($path);
    $this->delete('/vox/sync/locales', ['locale' => 'de'])->assertSessionHasNoErrors();
    $checkpoint = DB::connection(config('vox.database.connection'))->table('vox_checkpoints')->latest('id')->value('id');
    app(TranslationCheckpoints::class)->restore($checkpoint);
    expect(File::get($path))->toBe($before)
        ->and(app(VoxSettingsRepository::class)->provisionedLocales())->toContain('de')
        ->and(VoxTranslationValue::query()->where('locale', 'de')->exists())->toBeTrue();
});

it('exposes removal only for eligible dashboard languages', function (): void {
    app(LocaleProvisioner::class)->provision('de', useDefault: true);
    $this->get('/vox/sync')->assertInertia(fn (AssertableInertia $page) => $page->component('Sync', false)
        ->where('localeRemovalReasons.de', null)
        ->where('localeRemovalReasons.en', 'The default and fallback languages cannot be removed.')
        ->where('localeRemovalReasons.fr', 'Only languages added through Vox can be removed here.'));
});

it('refreshes approval after removing the only unapproved language', function (): void {
    app(VoxSettingsRepository::class)->save(['provisioned_locales' => ['de']]);
    $translation = VoxTranslation::factory()->withValues(['en' => 'Hello', 'de' => 'Hallo'])->create(['status' => 'pending']);
    $translation->values()->where('locale', 'en')->update(['is_approved' => true]);
    $translation->values()->where('locale', 'de')->update(['is_approved' => false]);
    $this->delete('/vox/sync/locales', ['locale' => 'de'])->assertSessionHasNoErrors();
    expect($translation->fresh()->status)->toBe('approved');
});
