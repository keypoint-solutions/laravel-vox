<?php

use Illuminate\Support\Facades\Route;
use KeypointSolutions\LaravelVox\Http\Controllers\FrontendTranslationsController;

Route::get('/translations/{locale}', FrontendTranslationsController::class)
    ->middleware(config('vox.frontend.runtime.middleware', []))
    ->name('translations.show');
