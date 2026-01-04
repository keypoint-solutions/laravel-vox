<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

$availableLocales = (function (): array {
    $langPath = lang_path();
    $locales = [];

    if (File::isDirectory($langPath)) {
        $directories = collect(File::directories($langPath))
            ->map(fn (string $path) => basename($path))
            ->filter(fn (string $locale) => $locale !== 'vendor')
            ->values()
            ->all();

        $locales = array_merge($locales, $directories);

        $jsonLocales = collect(File::files($langPath))
            ->filter(fn (\SplFileInfo $file) => Str::endsWith($file->getFilename(), '.json'))
            ->map(fn (\SplFileInfo $file) => Str::before($file->getFilename(), '.json'))
            ->values()
            ->all();

        $locales = array_merge($locales, $jsonLocales);
    }

    $locales = array_values(array_unique(array_filter($locales)));

    if ($locales === []) {
        $locales = [config('app.locale', 'en')];
    }

    return $locales;
})();

$localePattern = implode('|', array_map(
    fn (string $locale) => preg_quote($locale, '/'),
    $availableLocales
));

Route::view('/', 'app')->name('home');

Route::get('/auth/login', function (Request $request) {
    $user = User::query()->where('email', 'admin@keypoint.ro')->firstOrFail();

    Auth::login($user);
    $request->session()->regenerate();

    return redirect('/');
})->name('auth.login');

Route::get('/auth/logout', function (Request $request) {
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect('/');
})->name('auth.logout');

Route::prefix('{locale}')
    ->where(['locale' => $localePattern])
    ->group(function (): void {
        Route::view('/', 'app')->name('home.locale');

        Route::get('/auth/login', function (Request $request, string $locale) {
            $user = User::query()->where('email', 'admin@keypoint.ro')->firstOrFail();

            Auth::login($user);
            $request->session()->regenerate();

            return redirect("/{$locale}");
        })->name('auth.login.locale');

        Route::get('/auth/logout', function (Request $request, string $locale) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect("/{$locale}");
        })->name('auth.logout.locale');

        Route::fallback(fn () => view('app'));
    });

Route::fallback(fn () => view('app'));
