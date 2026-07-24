<?php

use Illuminate\Support\ServiceProvider;
use KeypointSolutions\LaravelVox\LaravelVoxServiceProvider;

it('registers the compiled dashboard as publishable package assets', function (): void {
    $paths = ServiceProvider::pathsToPublish(LaravelVoxServiceProvider::class, 'vox-assets');
    $sourceRegistered = collect(array_keys($paths))
        ->contains(fn (string $path): bool => realpath($path) === realpath(__DIR__.'/../dist/vox'));

    expect($sourceRegistered)->toBeTrue()
        ->and(array_values($paths))->toContain(public_path('vendor/vox'));
});
