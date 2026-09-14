<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use KeypointSolutions\LaravelVox\Models\VoxAudit;
use KeypointSolutions\LaravelVox\Models\VoxEnvironment;
use KeypointSolutions\LaravelVox\Models\VoxRemoteTranslation;
use KeypointSolutions\LaravelVox\Models\VoxSetting;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Models\VoxTranslationOccurrence;
use KeypointSolutions\LaravelVox\Models\VoxTranslationValue;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;
use KeypointSolutions\LaravelVox\Translation\TranslationResetter;

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('vox.system.bypass_auth_in_local', false);
    Gate::define('viewVox', fn (): bool => true);
    Gate::define('manageVoxSettings', fn (): bool => true);
    $user = new User;
    $user->id = 123;
    $this->actingAs($user);

    VoxTranslation::factory()->withValues(['en' => 'Hello', 'fr' => 'Bonjour'])
        ->withOccurrence('resources/js/app.ts')->create();
    $this->environment = VoxEnvironment::query()->create([
        'name' => 'Remote', 'type' => 'production', 'url' => 'https://example.test', 'secret_key' => 'secret',
    ]);
    $this->environment->forceFill(['sync_revision' => 5, 'last_pulled_at' => now()])->save();
    VoxRemoteTranslation::query()->create([
        'environment_id' => $this->environment->id, 'identity' => hash('sha256', 'hello'),
        'group' => 'messages', 'key' => 'hello', 'locale' => 'fr', 'remote_value' => 'Salut',
    ]);
    VoxSetting::query()->create(['key' => 'translate_guidance', 'value' => '"Keep me"']);
    app(VoxAuditLogger::class)->record('existing.event');

    prepareVoxFixtures();
    config()->set('vox.frontend.manifest', $this->fixtureRoot.'/frontend.json');
    config()->set('vox.frontend.runtime.path', $this->fixtureRoot.'/runtime');
    File::ensureDirectoryExists($this->fixtureRoot.'/runtime');
    File::put($this->fixtureRoot.'/runtime/en.json', '{"Hello":"Hello"}');
    $this->filesBefore = collect(File::allFiles($this->fixtureRoot))
        ->mapWithKeys(fn ($file): array => [$file->getPathname() => hash_file('sha256', $file->getPathname())])
        ->all();
});

it('resets only translations and keeps files settings environments and audit history', function (): void {
    $this->from('/vox/settings')->post('/vox/settings/reset', [
        'scope' => 'translations', 'confirmation' => 'RESET TRANSLATIONS',
    ])->assertRedirect('/vox/settings');

    expect(VoxTranslation::count())->toBe(0)
        ->and(VoxTranslationValue::count())->toBe(0)
        ->and(VoxTranslationOccurrence::count())->toBe(0)
        ->and(VoxRemoteTranslation::count())->toBe(0)
        ->and(VoxSetting::count())->toBe(1)
        ->and(VoxEnvironment::count())->toBe(1)
        ->and($this->environment->fresh()->sync_revision)->toBe(6)
        ->and($this->environment->fresh()->last_pulled_at)->toBeNull()
        ->and(VoxAudit::count())->toBe(2);

    $audit = VoxAudit::query()->where('action', 'data-reset')->sole();
    expect($audit->user_id)->toBe(123)
        ->and($audit->context['scope'])->toBe('translations')
        ->and($audit->context['deleted']['vox_translation_values'])->toBe(2);

    foreach ($this->filesBefore as $path => $hash) {
        expect(hash_file('sha256', $path))->toBe($hash);
    }
});

it('resets all Vox data while retaining published files and a new reset audit event', function (): void {
    $connection = DB::connection(config('vox.database.connection'));
    $migrations = $connection->table('migrations')->count();
    $this->from('/vox/settings')->post('/vox/settings/reset', [
        'scope' => 'all', 'confirmation' => 'RESET ALL VOX DATA',
    ])->assertRedirect('/vox/settings');

    foreach (['vox_translations', 'vox_translation_values', 'vox_translation_occurrences', 'vox_remote_translations', 'vox_settings', 'vox_environments'] as $table) {
        expect($connection->table($table)->count())->toBe(0);
    }
    expect(VoxAudit::count())->toBe(1)
        ->and(VoxAudit::sole()->action)->toBe('data-reset')
        ->and(VoxAudit::sole()->context['scope'])->toBe('all')
        ->and($connection->table('migrations')->count())->toBe($migrations);

    foreach ($this->filesBefore as $path => $hash) {
        expect(hash_file('sha256', $path))->toBe($hash);
    }
});

it('rejects absent incorrect or mismatched confirmation without deleting anything', function (array $payload): void {
    $this->postJson('/vox/settings/reset', $payload)->assertUnprocessable();
    expect(VoxTranslation::count())->toBe(1)->and(VoxSetting::count())->toBe(1)->and(VoxAudit::count())->toBe(1);
})->with([
    [[]],
    [['scope' => 'all']],
    [['scope' => 'all', 'confirmation' => 'RESET TRANSLATIONS']],
    [['scope' => 'translations', 'confirmation' => 'reset translations']],
    [['scope' => 'invalid', 'confirmation' => 'RESET ALL VOX DATA']],
]);

it('denies reset to viewers without settings permission', function (): void {
    Gate::define('manageVoxSettings', fn (): bool => false);
    $this->postJson('/vox/settings/reset', ['scope' => 'all', 'confirmation' => 'RESET ALL VOX DATA'])->assertForbidden();
    expect(VoxTranslation::count())->toBe(1)->and(VoxAudit::count())->toBe(1);
});

it('rolls back every deletion if audit recording fails', function (): void {
    $audit = Mockery::mock(VoxAuditLogger::class);
    $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit failed'));
    expect(fn () => app()->makeWith(TranslationResetter::class, ['audit' => $audit])->reset('all'))->toThrow(RuntimeException::class, 'Audit failed');
    expect(VoxTranslation::count())->toBe(1)
        ->and(VoxTranslationValue::count())->toBe(2)
        ->and(VoxTranslationOccurrence::count())->toBe(1)
        ->and(VoxRemoteTranslation::count())->toBe(1)
        ->and(VoxSetting::count())->toBe(1)
        ->and(VoxEnvironment::count())->toBe(1)
        ->and(VoxAudit::count())->toBe(1);
});

it('requires the typed CLI confirmation and leaves data alone when cancelled', function (): void {
    $this->artisan('vox:reset')->expectsQuestion('Type RESET TRANSLATIONS to continue', 'no')->assertFailed();
    expect(VoxTranslation::count())->toBe(1);
});

it('accepts typed CLI confirmation for the selected scope', function (): void {
    $this->artisan('vox:reset')->expectsQuestion('Type RESET TRANSLATIONS to continue', 'RESET TRANSLATIONS')->assertSuccessful();
    expect(VoxTranslation::count())->toBe(0)->and(VoxEnvironment::count())->toBe(1);
});

it('refuses unattended reset without force', function (): void {
    $this->artisan('vox:reset', ['--no-interaction' => true])->assertFailed();
    expect(VoxTranslation::count())->toBe(1);
});

it('supports explicit full reset automation and rejects unknown scopes', function (): void {
    $this->artisan('vox:reset', ['--scope' => 'invalid', '--force' => true])->assertFailed();
    expect(VoxTranslation::count())->toBe(1);
    $this->artisan('vox:reset', ['--scope' => 'all', '--force' => true])->assertSuccessful();
    expect(VoxTranslation::count())->toBe(0)->and(VoxSetting::count())->toBe(0)->and(VoxEnvironment::count())->toBe(0);
});

it('denies reset to unauthenticated requests', function (): void {
    auth()->forgetUser();
    $this->postJson('/vox/settings/reset', ['scope' => 'all', 'confirmation' => 'RESET ALL VOX DATA'])->assertForbidden();
    expect(VoxTranslation::count())->toBe(1);
});

it('clears discovery manifests for either reset scope without changing configuration or runtime files', function (string $scope): void {
    $dynamic = config('vox.dynamic_keys.manifest');
    $frontend = config('vox.frontend.manifest');
    File::put($dynamic, '{"patterns":[]}');
    File::put($frontend, '{"groups":["messages"]}');
    $patterns = config('vox.dynamic_keys.patterns');

    app(TranslationResetter::class)->reset($scope);

    expect(File::exists($dynamic))->toBeFalse()
        ->and(File::exists($frontend))->toBeFalse()
        ->and(config('vox.dynamic_keys.patterns'))->toBe($patterns);
    foreach ($this->filesBefore as $path => $hash) {
        expect(hash_file('sha256', $path))->toBe($hash);
    }

    app(TranslationResetter::class)->reset($scope);
})->with(['translations', 'all']);
