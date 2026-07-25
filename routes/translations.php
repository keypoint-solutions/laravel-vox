<?php

use Illuminate\Support\Facades\Route;
use KeypointSolutions\LaravelVox\Http\Controllers\FrontendLocalesController;
use KeypointSolutions\LaravelVox\Http\Controllers\FrontendTranslationsController;

Route::get('/locales', FrontendLocalesController::class)
    ->middleware(config('vox.frontend.runtime.middleware', []))
    ->name('locales.index');

Route::get('/translations/{locale}', FrontendTranslationsController::class)
    ->middleware(config('vox.frontend.runtime.middleware', []))
    ->name('translations.show');
