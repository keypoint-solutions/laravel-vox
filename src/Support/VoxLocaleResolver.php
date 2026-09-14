<?php

namespace KeypointSolutions\LaravelVox\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class VoxLocaleResolver
{
    public function __construct(
        private VoxSettingsRepository $settings,
        private VoxListConfiguration $listConfiguration,
    ) {}

    /**
     * @return array<int, string>
     */
    public function resolveLocales(): array
    {
        $configured = $this->listConfiguration->configuredValues('vox.translate.locales');
        $locales = $configured === null
            ? $this->discoverLocales()
            : $this->normalizeLocales($configured);

        $locales = $this->normalizeLocales(array_merge($locales, $this->settings->provisionedLocales()));
        $baseLocale = $this->resolveBaseLocale($locales);

        return $this->sortLocales(array_merge([$baseLocale], $locales));
    }

    /**
     * @param  array<int, string>  $locales
     * @return array<int, string>
     */
    public function sortLocales(array $locales): array
    {
        $locales = $this->normalizeLocales($locales);
        $baseLocale = $this->resolveBaseLocale($locales);
        sort($locales, SORT_STRING | SORT_FLAG_CASE);

        if (in_array($baseLocale, $locales, true)) {
            return array_values(array_unique(array_merge([$baseLocale], $locales)));
        }

        return $locales;
    }

    /**
     * @return array<int, string>
     */
    private function discoverLocales(): array
    {
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

    public function normalizeLocaleCode(string $locale): string
    {
        $locale = trim($locale);

        if (preg_match('/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/D', $locale) !== 1) {
            return '';
        }

        $locale = str_replace('-', '_', $locale);

        if (class_exists(\Locale::class)) {
            $canonical = \Locale::canonicalize($locale);

            if (is_string($canonical) && $canonical !== '') {
                return $canonical;
            }
        }

        $segments = explode('_', $locale);
        $segments[0] = strtolower($segments[0]);

        foreach (array_slice($segments, 1, null, true) as $index => $segment) {
            $segments[$index] = strlen($segment) === 4
                ? ucfirst(strtolower($segment))
                : strtoupper($segment);
        }

        return implode('_', $segments);
    }

    public function resolveLocale(string $requestedLocale): ?string
    {
        $normalized = $this->normalizeLocaleCode($requestedLocale);

        if ($normalized === '') {
            return null;
        }

        foreach ($this->resolveLocales() as $locale) {
            if ($this->normalizeLocaleCode($locale) === $normalized) {
                return $locale;
            }
        }

        return null;
    }

    public function resolveBaseLocale(array $locales): string
    {
        $configured = config('vox.translate.base_locale', 'auto');

        if (is_string($configured) && $configured !== 'auto') {
            return $configured;
        }

        return config('app.locale', 'en');
    }

    /**
     * @param  array<int, string>  $locales
     * @return array<int, string>
     */
    private function normalizeLocales(array $locales): array
    {
        return array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $locale): string => is_string($locale) ? trim($locale) : '',
                $locales
            ),
            static fn (string $locale): bool => $locale !== ''
        )));
    }
}
