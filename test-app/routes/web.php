<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use KeypointSolutions\LaravelVox\Support\VoxArchive;
use KeypointSolutions\LaravelVox\Support\VoxLocaleResolver;
use KeypointSolutions\LaravelVox\Translation\RemoteTranslationSnapshot;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

$localeResolver = app(VoxLocaleResolver::class);
$availableLocales = $localeResolver->resolveLocales();
$baseLocale = $localeResolver->resolveBaseLocale($availableLocales);
$fallbackDemoLocale = 'und';
$demoLocales = array_values(array_unique([...$availableLocales, $fallbackDemoLocale]));
$localePattern = implode('|', array_map(
    static fn (string $locale): string => preg_quote($locale, '/'),
    $demoLocales
));

Route::redirect('/', "/{$baseLocale}/vue")->name('home');

Route::post('/vox-demo-remote/sync', function (VoxArchive $archive, RemoteTranslationSnapshot $snapshot): JsonResponse|BinaryFileResponse {
    abort_unless(
        hash_equals('vox-demo-key', (string) request()->header('X-Vox-Key')),
        403
    );

    if (request()->wantsJson()) {
        return response()->json([
            'format' => RemoteTranslationSnapshot::FORMAT,
            'values' => $snapshot->fromDirectory(base_path('tests/Fixtures/remote-lang')),
        ])->header('Cache-Control', 'no-store');
    }

    $archivePath = $archive->createLangArchive(
        base_path('tests/Fixtures/remote-lang')
    );

    return response()->download($archivePath)->deleteFileAfterSend(true);
})->withoutMiddleware(PreventRequestForgery::class)->name('vox-demo-remote.sync');

Route::prefix('{locale}')
    ->where(['locale' => $localePattern])
    ->group(function () use ($baseLocale, $demoLocales, $fallbackDemoLocale): void {
        Route::get('/vue', function (string $locale) use ($baseLocale, $fallbackDemoLocale) {
            app()->setLocale($locale);

            return view('app', [
                'fallbackDemoLocale' => $fallbackDemoLocale,
                'fallbackLocale' => $baseLocale,
                'requestedLocale' => $locale,
            ]);
        })->name('translations.vue');

        Route::get('/blade', function (string $locale) use (
            $baseLocale,
            $demoLocales,
            $fallbackDemoLocale
        ) {
            app()->setLocale($locale);

            return view('welcome', [
                'availableLocales' => $demoLocales,
                'fallbackDemoLocale' => $fallbackDemoLocale,
                'fallbackLocale' => $baseLocale,
                'locale' => $locale,
            ]);
        })->name('translations.blade');
    });
