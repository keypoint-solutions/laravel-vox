<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Browser');

beforeEach(function (): void {
    $this->voxBrowserRuntimePath = storage_path('framework/testing/vox-browser-runtime/'.Str::uuid());

    config()->set('vox.frontend.runtime.path', $this->voxBrowserRuntimePath);
});

afterEach(function (): void {
    File::deleteDirectory($this->voxBrowserRuntimePath);
});
