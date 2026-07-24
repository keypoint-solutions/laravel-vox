<?php

namespace KeypointSolutions\LaravelVox\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class VoxLocaleResolver
{
    /**
     * @return array<int, string>
     */
    public function resolveLocales(): array
    {
        $configured = config('vox.translate.locales', 'auto');

        if (is_array($configured)) {
            return $this->normalizeLocales($configured);
        }

        if (is_string($configured) && $configured !== 'auto') {
            return $this->normalizeLocales(array_filter(array_map('trim', explode(',', $configured))));
        }

        $supportedLocales = config('laravellocalization.supportedLocales');

        if (is_array($supportedLocales)) {
            return $this->normalizeLocales(array_keys($supportedLocales));
        }

        $langPath = config('vox.paths.lang', resource_path('lang'));

        if (File::isDirectory($langPath)) {
            $directories = collect(File::directories($langPath))
                ->map(fn (string $path) => basename($path))
                ->filter(fn (string $locale) => $locale !== 'vendor')
                ->values()
                ->all();

            if ($directories !== []) {
                return $this->normalizeLocales($directories);
            }

            $jsonLocales = collect(File::files($langPath))
                ->filter(fn (\SplFileInfo $file) => Str::endsWith($file->getFilename(), '.json'))
                ->map(fn (\SplFileInfo $file) => Str::before($file->getFilename(), '.json'))
                ->values()
                ->all();

            if ($jsonLocales !== []) {
                return $this->normalizeLocales($jsonLocales);
            }
        }

        return $this->normalizeLocales([config('app.locale', 'en')]);
    }

    public function resolveBaseLocale(array $locales): string
    {
        $configured = config('vox.translate.base_locale', 'auto');

        if (is_string($configured) && $configured !== 'auto') {
            return $configured;
        }

        return Arr::first($locales) ?? config('app.locale', 'en');
    }

    /**
     * @param  array<int, string>  $locales
     * @return array<int, string>
     */
    private function normalizeLocales(array $locales): array
    {
        return array_values(array_unique(array_filter($locales)));
    }
}
