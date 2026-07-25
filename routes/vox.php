<?php

use Illuminate\Support\Facades\Route;
use KeypointSolutions\LaravelVox\Http\Controllers\AuditController;
use KeypointSolutions\LaravelVox\Http\Controllers\DashboardController;
use KeypointSolutions\LaravelVox\Http\Controllers\DownloadTranslationArchiveController;
use KeypointSolutions\LaravelVox\Http\Controllers\ImportTranslationArchiveController;
use KeypointSolutions\LaravelVox\Http\Controllers\ManageController;
use KeypointSolutions\LaravelVox\Http\Controllers\ManageTranslationController;
use KeypointSolutions\LaravelVox\Http\Controllers\PublishController;
use KeypointSolutions\LaravelVox\Http\Controllers\PullRemoteTranslationsController;
use KeypointSolutions\LaravelVox\Http\Controllers\SettingsController;
use KeypointSolutions\LaravelVox\Http\Controllers\SyncController;
use KeypointSolutions\LaravelVox\Http\Controllers\SyncEnvironmentController;
use KeypointSolutions\LaravelVox\Http\Controllers\SyncLocalTranslationsController;
use KeypointSolutions\LaravelVox\Http\Controllers\SyncPageController;
use KeypointSolutions\LaravelVox\Http\Middleware\Authorize;

$configMiddleware = config('vox.system.middleware', []);

require __DIR__.'/translations.php';

Route::post('/sync', SyncController::class)
    ->middleware(config('vox.sync.middleware', []))
    ->name('sync.remote');

Route::middleware($configMiddleware)
    ->group(function (): void {
        Route::get('/', DashboardController::class)->name('dashboard');

        Route::get('/sync', SyncPageController::class)->name('sync');
        Route::post('/sync/local', SyncLocalTranslationsController::class)->name('sync.local');
        Route::get('/sync/archive', DownloadTranslationArchiveController::class)->name('sync.archive.download');
        Route::post('/sync/archive', ImportTranslationArchiveController::class)->name('sync.archive.import');
        Route::post('/sync/environments', [SyncEnvironmentController::class, 'store'])
            ->name('sync.environments.store');
        Route::put('/sync/environments/{environment}', [SyncEnvironmentController::class, 'update'])
            ->name('sync.environments.update');
        Route::delete('/sync/environments/{environment}', [SyncEnvironmentController::class, 'destroy'])
            ->name('sync.environments.destroy');
        Route::post('/sync/environments/{environment}/pull', PullRemoteTranslationsController::class)
            ->name('sync.environments.pull');

        Route::get('/manage', ManageController::class)->name('manage');

        Route::post('/manage/translations', [ManageTranslationController::class, 'store'])
            ->name('manage.translations.store');
        Route::patch('/manage/translations/{translation}', [ManageTranslationController::class, 'update'])
            ->name('manage.translations.update');
        Route::post('/manage/translations/{translation}/toggle-approval',
            [ManageTranslationController::class, 'toggleApproval'])
            ->name('manage.translations.toggle-approval');
        Route::post('/manage/translations/bulk-approval', [ManageTranslationController::class, 'bulkApproval'])
            ->name('manage.translations.bulk-approval');
        Route::post('/manage/translations/bulk-translate', [ManageTranslationController::class, 'bulkTranslate'])
            ->name('manage.translations.bulk-translate');
        Route::post('/manage/translations/{translation}/translate', [ManageTranslationController::class, 'translate'])
            ->name('manage.translations.translate');

        Route::get('/publish', [PublishController::class, 'index'])->name('publish');
        Route::post('/publish', [PublishController::class, 'store'])->name('publish.store');

        Route::get('/audit', AuditController::class)->name('audit');

        Route::middleware(Authorize::class.':manageVoxSettings')->group(function (): void {
            Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
            Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
            Route::post('/settings/ai/models', [SettingsController::class, 'refreshModels'])
                ->name('settings.ai.models.refresh');
        });
    });
