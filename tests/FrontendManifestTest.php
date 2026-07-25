<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Support\VoxFrontendManifest;

beforeEach(function (): void {
    $this->frontendManifestPath = base_path('tests/.tmp/frontend-'.Str::uuid().'.json');
    config()->set('vox.frontend.manifest', $this->frontendManifestPath);
    config()->set('vox.frontend.groups.mode', 'auto');
    config()->set('vox.frontend.groups.values', '');
});

afterEach(function (): void {
    if (File::exists($this->frontendManifestPath)) {
        File::delete($this->frontendManifestPath);
    }
});

it('writes only groups discovered in frontend source', function (): void {
    app(VoxFrontendManifest::class)->writeFromScanResults([
        'frontend.welcome' => [
            'group' => 'frontend',
            'key' => 'welcome',
            'is_frontend' => true,
        ],
        'messages.saved' => [
            'group' => 'messages',
            'key' => 'saved',
            'is_frontend' => false,
        ],
        'This is JSON' => [
            'group' => null,
            'key' => 'This is JSON',
            'is_frontend' => true,
        ],
    ]);

    expect(json_decode(File::get($this->frontendManifestPath), true))->toBe([
        'groups' => ['frontend'],
    ]);
});

it('includes groups discovered only through dynamic frontend patterns', function (): void {
    app(VoxFrontendManifest::class)->writeFromScanResults([], [[
        'pattern' => 'enums.user_roles.*',
        'prefix' => 'enums.user_roles.',
        'suffix' => '',
        'is_frontend' => true,
    ]]);

    expect(json_decode(File::get($this->frontendManifestPath), true))->toBe([
        'groups' => ['enums'],
    ]);
});

it('allows an explicit frontend group override', function (): void {
    config()->set('vox.frontend.groups.mode', 'configured');
    config()->set('vox.frontend.groups.values', ['shared', 'checkout']);

    app(VoxFrontendManifest::class)->write(['frontend']);

    expect(json_decode(File::get($this->frontendManifestPath), true))->toBe([
        'groups' => ['checkout', 'shared'],
    ]);
});

it('can refresh the frontend manifest from synchronized database flags', function (): void {
    VoxTranslation::factory()->frontend()->create(['group' => 'frontend']);
    VoxTranslation::factory()->frontend()->json()->create();
    VoxTranslation::factory()->create(['group' => 'backend', 'is_frontend' => false]);

    app(VoxFrontendManifest::class)->writeFromDatabase();

    expect(json_decode(File::get($this->frontendManifestPath), true))->toBe([
        'groups' => ['frontend'],
    ]);
});
