<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\DynamicKeyProvider;
use KeypointSolutions\LaravelVox\LaravelVox;
use KeypointSolutions\LaravelVox\Support\VoxDynamicKeyRegistry;
use KeypointSolutions\LaravelVox\Support\VoxSettingsRepository;

enum DynamicRegistryRole: string
{
    case Admin = 'admin';
    case Editor = 'editor';
}

class DynamicRegistryProvider implements DynamicKeyProvider
{
    public function values(): iterable
    {
        return ['draft', 'published'];
    }
}

beforeEach(function (): void {
    $this->dynamicManifestPath = base_path('tests/.tmp/dynamic-'.Str::uuid().'.json');
    config()->set('vox.dynamic_keys.manifest', $this->dynamicManifestPath);
    config()->set('vox.dynamic_keys.patterns', []);
    config()->set('vox.dynamic_keys.bindings', []);
    config()->set('vox.parse.protected_keys', []);
});

afterEach(function (): void {
    if (File::exists($this->dynamicManifestPath)) {
        File::delete($this->dynamicManifestPath);
    }
});

it('unions config, UI, and detected dynamic patterns with source metadata', function (): void {
    config()->set('vox.dynamic_keys.patterns', ['validation.*']);
    app(VoxSettingsRepository::class)->save([
        'dynamic_key_patterns' => ['enums.user_roles.*'],
    ]);
    $registry = app(VoxDynamicKeyRegistry::class);
    $registry->writeDetectedPatterns([[
        'pattern' => 'frontend.labels.*',
        'prefix' => 'frontend.labels.',
        'suffix' => '',
        'source' => '$t',
        'is_frontend' => true,
        'file' => 'resources/js/Welcome.vue',
        'line' => 20,
        'context' => '$t(`frontend.labels.${label}`)',
    ]]);

    $entries = collect($registry->entries())->keyBy('pattern');

    expect($entries->keys()->all())->toBe([
        'enums.user_roles.*',
        'frontend.labels.*',
        'validation.*',
    ])->and($entries['validation.*']['sources'])->toContain('config')
        ->and($entries['enums.user_roles.*']['sources'])->toContain('settings')
        ->and($entries['frontend.labels.*']['sources'])->toContain('detected')
        ->and($entries['frontend.labels.*']['is_frontend'])->toBeTrue()
        ->and($registry->matches('custom.email.required', 'validation'))->toBeTrue()
        ->and($registry->matches('labels.title', 'frontend'))->toBeTrue();
});

it('supports arrays, enums, providers, and runtime callbacks as closed bindings', function (): void {
    config()->set('vox.dynamic_keys.bindings', [
        'enums.user_roles.*' => DynamicRegistryRole::class,
        'enums.states.*' => ['enabled', 'disabled'],
        'posts.statuses.*' => DynamicRegistryProvider::class,
    ]);
    app(LaravelVox::class)->dynamicKeys('features.*', fn (): array => ['search', 'exports']);

    $registry = app(VoxDynamicKeyRegistry::class);
    $results = $registry->enumeratedScanResults();

    expect($results)->toHaveKeys([
        'enums.user_roles.admin',
        'enums.user_roles.editor',
        'enums.states.enabled',
        'enums.states.disabled',
        'posts.statuses.draft',
        'posts.statuses.published',
        'features.search',
        'features.exports',
    ])->and($registry->matches('user_roles.admin', 'enums'))->toBeTrue()
        ->and($registry->matches('user_roles.owner', 'enums'))->toBeFalse()
        ->and($registry->patternAccepts('enums.user_roles.*', 'enums.user_roles.owner'))->toBeFalse();
});

it('seeds enum-bound keys during parse without creating wildcard samples', function (): void {
    $targetRoot = prepareVoxFixtures();
    config()->set('vox.dynamic_keys.bindings', [
        'enums.user_roles.*' => DynamicRegistryRole::class,
    ]);

    $this->artisan('vox:parse', ['--no-interaction' => true])->assertExitCode(0);

    $english = require $targetRoot.'/lang/en/enums.php';
    $french = require $targetRoot.'/lang/fr/enums.php';

    expect($english)->toHaveKeys(['user_roles.admin', 'user_roles.editor'])
        ->not->toHaveKey('user_roles.VALUE')
        ->and($french['user_roles.admin'])->toStartWith('🚩');
});
