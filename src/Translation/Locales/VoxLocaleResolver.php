<?php

namespace KeypointSolutions\LaravelVox\Translation\Locales;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Support\VoxConfig;
use KeypointSolutions\LaravelVox\Support\VoxSettingsRepository;
use KeypointSolutions\LaravelVox\Translation\TranslationFallbackRules;

class VoxLocaleResolver
{
    public function __construct(
        private VoxSettingsRepository $settings,
        private VoxLocaleDeclaration $declaration,
    ) {}

    /**
     * Locales the dashboard manages: published locales plus locales added through Vox, which are stored in its database.
     *
     * @return array<int, string>
     */
    public function managedLocales(): array
    {
        $locales = array_merge($this->publishedLocales(), $this->settings->provisionedLocales(), $this->manifestLocales());

        return $this->sortLocales(array_merge([$this->resolveBaseLocale($locales)], $locales));
    }

    /**
     * Resolve the managed locales with the base locale listed first.
     *
     * @return array{0: array<int, string>, 1: string}
     */
    public function managedLocalesWithBase(): array
    {
        $locales = $this->managedLocales();
        $baseLocale = $this->resolveBaseLocale($locales);

        return [array_values(array_unique(array_merge([$baseLocale], $locales))), $baseLocale];
    }

    /**
     * Locales the application serves at runtime, read from configuration and files only, never from the Vox database.
     *
     * @return array<int, string>
     */
    public function publishedLocales(): array
    {
        return $this->sortLocales(array_merge($this->sourceLocales(), $this->declaration->isDeclarative() ? [] : $this->frontendArtifactLocales()));
    }

    /**
     * Published locales that have language files to build frontend catalogues from, excluding the generated catalogues.
     *
     * @return array<int, string>
     */
    public function sourceLocales(): array
    {
        $locales = $this->declaration->locales() ?? array_merge($this->discoverLocales(), $this->manifestLocales());
        $locales = $this->normalizeLocales($locales);

        return $this->sortLocales(array_merge([$this->resolveBaseLocale($locales)], $locales));
    }

    /**
     * Whether the application declares this locale; every locale is declared in auto mode.
     */
    public function isDeclared(string $locale): bool
    {
        $declared = $this->declaration->locales();

        return $declared === null || $this->resolveLocale($locale, [...$declared, $this->resolveBaseLocale($declared)]) !== null;
    }

    /**
     * Declared locales that have no language files or fallback rule yet, so Vox can still provision them.
     *
     * @return array<int, string>
     */
    public function unprovisionedLocales(): array
    {
        $declared = $this->declaration->locales();

        if ($declared === null) {
            return [];
        }

        $baseLocale = $this->resolveBaseLocale($declared);
        $manifestLocales = $this->manifestLocales();

        return array_values(array_filter(
            $this->normalizeLocales($declared),
            fn (string $locale): bool => $locale !== $baseLocale
                && ! in_array($locale, $manifestLocales, true)
                && ! $this->hasLanguageFiles($locale)
        ));
    }

    /**
     * Whether Vox can add this locale: it is new, or declared by the application but not provisioned yet.
     */
    public function canProvision(string $locale): bool
    {
        return $this->resolveLocale($locale) === null
            || $this->resolveLocale($locale, $this->unprovisionedLocales()) !== null;
    }

    private function hasLanguageFiles(string $locale): bool
    {
        $langPath = VoxConfig::langPath();

        return File::isDirectory($langPath.'/'.$locale) || File::isFile($langPath.'/'.$locale.'.json');
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
    private function frontendArtifactLocales(): array
    {
        $path = config('vox.frontend.runtime.path', storage_path('vox/frontend-translations'));
        $locales = [];

        if (File::isDirectory($path)) {
            foreach (File::files($path) as $file) {
                $locale = $file->getBasename('.json');
                if ($file->getExtension() === 'json' && $this->normalizeLocaleCode($locale) !== '') {
                    $locales[] = $locale;
                }
            }
        }

        return $locales;
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
     * Discover locales from the application's own language directories and JSON files. Vendor translations do not
     * add locales, since packages often ship more languages than the application uses.
     *
     * @return array<int, string>
     */
    private function discoverLocales(): array
    {
        $langPath = VoxConfig::langPath();

        if (! File::isDirectory($langPath)) {
            return [];
        }

        $directories = collect(File::directories($langPath))
            ->map(fn (string $path): string => basename($path))
            ->filter(fn (string $locale): bool => $locale !== 'vendor');
        $jsonLocales = collect(File::files($langPath))
            ->filter(fn (\SplFileInfo $file): bool => $file->getExtension() === 'json'
                && $file->getFilename() !== TranslationFallbackRules::MANIFEST
                && ! Str::startsWith($file->getFilename(), 'php_'))
            ->map(fn (\SplFileInfo $file): string => $file->getBasename('.json'));

        return $directories->merge($jsonLocales)
            ->filter(fn (string $locale): bool => $this->normalizeLocaleCode($locale) !== '')
            ->unique()
            ->values()
            ->all();
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

        foreach ($locales ?? $this->managedLocales() as $locale) {
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
