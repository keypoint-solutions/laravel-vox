<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use KeypointSolutions\LaravelVox\Http\Controllers\SyncController;

$configMiddleware = config('vox.system.middleware', []);

Route::post('/vox/sync', SyncController::class)
    ->middleware(config('vox.sync.middleware', []))
    ->name('vox.sync.remote');

Route::prefix('vox')
    ->name('vox.')
    ->middleware($configMiddleware)
    ->group(function (): void {
        Route::get('/', function () {
            return Inertia::render('Dashboard');
        })->name('dashboard');

        Route::get('/sync', function () {
            return Inertia::render('Sync');
        })->name('sync');

        Route::get('/manage', function () {
            return Inertia::render('Manage');
        })->name('manage');

        Route::get('/publish', function () {
            return Inertia::render('Publish');
        })->name('publish');

        Route::get('/audit', function () {
            return Inertia::render('Audit');
        })->name('audit');

        Route::get('/settings', function () {
            return Inertia::render('Settings');
        })->name('settings');
    });
