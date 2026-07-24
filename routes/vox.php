<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use KeypointSolutions\LaravelVox\Http\Controllers\DashboardController;
use KeypointSolutions\LaravelVox\Http\Controllers\ManageController;
use KeypointSolutions\LaravelVox\Http\Controllers\ManageTranslationController;
use KeypointSolutions\LaravelVox\Http\Controllers\SettingsController;
use KeypointSolutions\LaravelVox\Http\Controllers\SyncController;
use KeypointSolutions\LaravelVox\Http\Middleware\Authorize;

$configMiddleware = config('vox.system.middleware', []);

Route::post('/sync', SyncController::class)
    ->middleware(config('vox.sync.middleware', []))
    ->name('sync.remote');

Route::middleware($configMiddleware)
    ->group(function (): void {
        Route::get('/', DashboardController::class)->name('dashboard');

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

        Route::middleware(Authorize::class.':manageVoxSettings')->group(function (): void {
            Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
            Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
            Route::post('/settings/ai/models', [SettingsController::class, 'refreshModels'])
                ->name('settings.ai.models.refresh');
        });
    });
