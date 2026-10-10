<?php

namespace KeypointSolutions\LaravelVox\Translation\Locales;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Support\VoxConfig;
use KeypointSolutions\LaravelVox\Support\VoxListConfiguration;
use KeypointSolutions\LaravelVox\Support\VoxSettingsRepository;
use KeypointSolutions\LaravelVox\Translation\TranslationFallbackRules;

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
        $locales = $this->normalizeLocales(array_merge($this->applicationLocales(), $this->settings->provisionedLocales(), $this->manifestLocales()));
        $baseLocale = $this->resolveBaseLocale($locales);

        return $this->sortLocales(array_merge([$baseLocale], $locales));
    }

    /**
     * Resolve the managed locales with the base locale listed first.
     *
     * @return array{0: array<int, string>, 1: string}
     */
    public function resolveLocalesWithBase(): array
    {
        $locales = $this->resolveLocales();
        $baseLocale = $this->resolveBaseLocale($locales);

        return [array_values(array_unique(array_merge([$baseLocale], $locales))), $baseLocale];
    }

    /** @return array<int, string> */
    public function resolveFileLocales(): array
    {
        $locales = array_merge($this->applicationLocales(), $this->manifestLocales());

        return $this->sortLocales(array_merge([$this->resolveBaseLocale($locales)], $locales));
    }

    /** @return array<int, string> */
    private function manifestLocales(): array
    {
        $langPath = VoxConfig::langPath();
        $manifest = $langPath.'/'.TranslationFallbackRules::MANIFEST;
        $locales = [];
        if (File::exists($manifest)) {
            foreach (TranslationFallbackRules::validateManifest(File::get($manifest)) as $rule) {
                $locale = $rule['locale'];
                if ($rule['published_mode'] !== 'inherit' || File::isDirectory($langPath.'/'.$locale)
                    || File::isFile($langPath.'/'.$locale.'.json') || File::glob($langPath.'/vendor/*/'.$locale) !== []
                    || File::glob($langPath.'/vendor/*/'.$locale.'.json') !== []) {
                    $locales[] = $locale;
                }
            }
        }

        return array_values(array_unique($locales));
    }

    /** @return array<int, string> */
    public function resolveRuntimeLocales(): array
    {
        $locales = $this->applicationLocales();
        $path = config('vox.frontend.runtime.path', storage_path('vox/frontend-translations'));

        if (File::isDirectory($path)) {
            foreach (File::files($path) as $file) {
                $locale = $file->getBasename('.json');
                if ($file->getExtension() === 'json' && $this->normalizeLocaleCode($locale) !== '') {
                    $locales[] = $locale;
                }
            }
        }

        return $this->sortLocales(array_merge([$this->resolveBaseLocale($locales)], $locales));
    }

    /** @return array<int, string> */
    private function applicationLocales(): array
    {
        $configured = $this->listConfiguration->configuredValues('vox.translate.locales');

        return $configured === null ? $this->discoverLocales() : $this->normalizeLocales($configured);
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

        $langPath = VoxConfig::langPath();

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
                ->filter(fn (\SplFileInfo $file) => Str::endsWith($file->getFilename(), '.json') && $file->getFilename() !== TranslationFallbackRules::MANIFEST)
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

    /** @param array<int, string>|null $locales */
    public function resolveLocale(string $requestedLocale, ?array $locales = null): ?string
    {
        $normalized = $this->normalizeLocaleCode($requestedLocale);

        if ($normalized === '') {
            return null;
        }

        foreach ($locales ?? $this->resolveLocales() as $locale) {
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
