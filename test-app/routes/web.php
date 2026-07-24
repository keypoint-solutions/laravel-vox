<?php

use Illuminate\Support\Facades\Route;
use KeypointSolutions\LaravelVox\Support\VoxArchive;
use KeypointSolutions\LaravelVox\Support\VoxLocaleResolver;

$localeResolver = app(VoxLocaleResolver::class);
$availableLocales = $localeResolver->resolveLocales();
$baseLocale = $localeResolver->resolveBaseLocale($availableLocales);
$localePattern = implode('|', array_map(
    static fn (string $locale): string => preg_quote($locale, '/'),
    $availableLocales
));

Route::redirect('/', "/{$baseLocale}/vue")->name('home');

Route::post('/vox-demo-remote/sync', function (VoxArchive $archive) {
    abort_unless(
        hash_equals('vox-demo-key', (string) request()->header('X-Vox-Key')),
        403
    );

    $archivePath = $archive->createLangArchive(
        base_path('tests/Fixtures/remote-lang')
    );

    return response()->download($archivePath)->deleteFileAfterSend(true);
})->name('vox-demo-remote.sync');

Route::prefix('{locale}')
    ->where(['locale' => $localePattern])
    ->group(function () use ($availableLocales): void {
        Route::get('/vue', function (string $locale) {
            app()->setLocale($locale);

            return view('app');
        })->name('translations.vue');

        Route::get('/blade', function (string $locale) use ($availableLocales) {
            app()->setLocale($locale);

            return view('welcome', [
                'availableLocales' => $availableLocales,
                'locale' => $locale,
            ]);
        })->name('translations.blade');
    });
