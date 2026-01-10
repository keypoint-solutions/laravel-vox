<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use KeypointSolutions\LaravelVox\Http\Controllers\ManageController;
use KeypointSolutions\LaravelVox\Http\Controllers\ManageTranslationController;
use KeypointSolutions\LaravelVox\Http\Controllers\SettingsController;
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

        Route::get('/manage', ManageController::class)->name('manage');

        Route::patch('/manage/translations/{translation}', [ManageTranslationController::class, 'update'])
            ->name('manage.translations.update');
        Route::post('/manage/translations/{translation}/toggle-approval',
            [ManageTranslationController::class, 'toggleApproval'])
            ->name('manage.translations.toggle-approval');
        Route::post('/manage/translations/{translation}/translate', [ManageTranslationController::class, 'translate'])
            ->name('manage.translations.translate');

        Route::get('/publish', function () {
            return Inertia::render('Publish');
        })->name('publish');

        Route::get('/audit', function () {
            return Inertia::render('Audit');
        })->name('audit');

        Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
        Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
    });
