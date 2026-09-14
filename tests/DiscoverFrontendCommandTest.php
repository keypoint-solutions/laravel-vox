<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use KeypointSolutions\LaravelVox\Support\VoxFrontendManifest;

beforeEach(function (): void {
    $root = prepareVoxFixtures();
    config()->set('vox.frontend.manifest', $root.'/frontend.json');
    config()->set('vox.frontend.groups.mode', 'auto');
    config()->set('vox.parse.paths', [$root.'/source']);
    File::ensureDirectoryExists($root.'/source');
});

it('discovers frontend groups without database queries or editing language files', function (): void {
    $root = $this->fixtureRoot;
    File::put($root.'/source/Page.vue', <<<'VUE'
<template>{{ $t('appointments.title') }} {{ $t('statuses.' + status) }}</template>
VUE);
    File::put($root.'/source/Backend.php', "<?php __('emails.subject');");
    File::put($root.'/source/Excluded.vue', "{{ \$t('private.title') }}");
    config()->set('vox.parse.exclude', ['*/Excluded.vue']);
    $before = File::get($root.'/lang/en/messages.php');
    DB::listen(function (): void {
        throw new RuntimeException('Discovery must not query the database');
    });

    $this->artisan('vox:frontend-discover')->assertSuccessful();

    expect(app(VoxFrontendManifest::class)->groups())->toBe(['appointments', 'statuses'])
        ->and(File::get($root.'/lang/en/messages.php'))->toBe($before)
        ->and(File::exists($root.'/dynamic.json'))->toBeFalse();
});

it('does not rewrite unchanged groups and removes the last source reference', function (): void {
    $root = $this->fixtureRoot;
    File::put($root.'/source/Page.vue', "{{ \$t('appointments.title') }}");
    $this->artisan('vox:frontend-discover')->assertSuccessful();
    $manifest = $root.'/frontend.json';
    touch($manifest, 1000000000);
    File::put($root.'/source/Page.vue', "{{ \$t('appointments.other') }}");
    $this->artisan('vox:frontend-discover')->assertSuccessful();
    clearstatcache(true, $manifest);
    expect(filemtime($manifest))->toBe(1000000000);

    File::delete($root.'/source/Page.vue');
    $this->artisan('vox:frontend-discover')->assertSuccessful();
    expect(app(VoxFrontendManifest::class)->groups())->toBe([]);
});

it('preserves configured selection instead of discovering groups', function (): void {
    config()->set('vox.frontend.groups.mode', 'configured');
    config()->set('vox.frontend.groups.values', ['labels']);
    File::put($this->fixtureRoot.'/source/Page.vue', "{{ \$t('appointments.title') }}");
    $this->artisan('vox:frontend-discover')->assertSuccessful();
    expect(json_decode(File::get($this->fixtureRoot.'/frontend.json'), true))->toBe(['groups' => ['labels']]);
});
