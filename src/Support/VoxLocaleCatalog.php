<?php

namespace KeypointSolutions\LaravelVox\Support;

use Illuminate\Support\Facades\File;
use KeypointSolutions\LaravelVox\Translation\FrontendTranslationArtifacts;

class VoxLocaleCatalog
{
    public function __construct(
        private VoxLocaleResolver $localeResolver,
        private FrontendTranslationArtifacts $artifacts,
    ) {}

    /**
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
    public function all(): array
    {
        $locales = $this->localeResolver->resolveLocales();
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
