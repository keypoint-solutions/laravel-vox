<?php

namespace KeypointSolutions\LaravelVox\Translation\Locales;

use Illuminate\Support\Facades\File;
use KeypointSolutions\LaravelVox\Translation\Publishing\FrontendTranslationArtifacts;

class VoxLocaleCatalog
{
    public function __construct(
        private VoxLocaleResolver $localeResolver,
        private FrontendTranslationArtifacts $artifacts,
        private VoxLocaleDeclaration $declaration,
    ) {}

    /**
     * @param  array<int, string>|null  $locales
     * @return array{
     *     default_locale: string,
     *     locales: array<int, array{
     *         code: string,
     *         name: string,
     *         is_default: bool,
     *         has_runtime_translations: bool
     *     }>
     * }
     */
    public function all(?array $locales = null): array
    {
        $locales ??= $this->localeResolver->managedLocales();
        $baseLocale = $this->localeResolver->resolveBaseLocale($locales);
        $locales = array_values(array_unique(array_merge([$baseLocale], $locales)));

        return [
            'default_locale' => $baseLocale,
            'locales' => array_map(
                fn (string $locale): array => [
                    'code' => $locale,
                    'name' => $this->displayName($locale),
                    'is_default' => $locale === $baseLocale,
                    'has_runtime_translations' => File::isFile($this->artifacts->pathForLocale($locale)),
                ],
                $locales
            ),
        ];
    }

    /**
     * Why a managed locale is not served as expected at runtime, or null when it is.
     */
    public function warning(string $locale): ?string
    {
        if (! $this->localeResolver->isDeclared($locale)) {
            return "Not listed in {$this->declaration->sourceLabel()}, so it is not served at runtime. Add it there to publish it.";
        }

        if ($this->localeResolver->resolveLocale($locale, $this->localeResolver->unprovisionedLocales()) !== null) {
            return "Listed in {$this->declaration->sourceLabel()} but has no language files yet. Add it below to create them.";
        }

        return null;
    }

    public function displayName(string $locale): string
    {
        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayName(str_replace('_', '-', $locale), 'en');

            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return $locale;
    }
}
