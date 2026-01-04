<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SetLocaleFromRoute
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $availableLocales = $this->availableLocales();
        $routeLocale = $request->route('locale');

        if (is_string($routeLocale) && in_array($routeLocale, $availableLocales, true)) {
            app()->setLocale($routeLocale);
        }

        View::share('availableLocales', $availableLocales);
        View::share('locale', app()->getLocale());

        return $next($request);
    }

    /**
     * @return array<int, string>
     */
    private function availableLocales(): array
    {
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
    }
}
