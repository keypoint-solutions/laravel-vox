<?php

namespace KeypointSolutions\LaravelVox\Translation;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use KeypointSolutions\LaravelVox\Support\VoxFrontendManifest;
use KeypointSolutions\LaravelVox\Support\VoxLocaleResolver;

class FrontendTranslationArtifacts
{
    public function __construct(
        private TranslationFileRepository $files,
        private TranslationFileValidator $validator,
        private VoxFrontendManifest $manifest,
        private VoxLocaleResolver $localeResolver,
    ) {}

    /**
     * @return array<int, string>
     */
    public function publish(?string $langPath = null): array
    {
        $files = $langPath === null ? $this->files : $this->files->forPath($langPath);
        $outputPath = $this->outputPath();
        File::ensureDirectoryExists($outputPath);
        $publishedFiles = [];
        $locales = $this->localeResolver->resolveLocales();

        foreach ($locales as $locale) {
            $translations = $this->translationsForLocale($files, $locale);
            ksort($translations);
            $contents = json_encode(
                $translations,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            )."\n";
            $path = $this->pathForLocale($locale);
            $this->validator->validateContents($contents, $path);
            app(TranslationFileTransaction::class)->replace($path, $contents);

            $publishedFiles[] = $path;
        }

        foreach (File::files($outputPath) as $file) {
            if (
                strtolower($file->getExtension()) === 'json'
                && ! in_array($file->getPathname(), $publishedFiles, true)
            ) {
                app(TranslationFileTransaction::class)->delete($file->getPathname());
            }
        }

        sort($publishedFiles);

        return $publishedFiles;
    }

    public function pathForLocale(string $locale): string
    {
        return $this->outputPath().DIRECTORY_SEPARATOR.$locale.'.json';
    }

    public function outputPath(): string
    {
        return rtrim(
            (string) config('vox.frontend.runtime.path', storage_path('vox/frontend-translations')),
            DIRECTORY_SEPARATOR
        );
    }

    /**
     * @return array<string, string>
     */
    private function translationsForLocale(TranslationFileRepository $files, string $locale): array
    {
        $translations = [];

        foreach ($this->groupsForLocale($files, $locale) as $group) {
            foreach (Arr::dot($files->loadGroup($locale, $group)) as $key => $value) {
                if (is_string($value)) {
                    $translations[str_replace(DIRECTORY_SEPARATOR, '.', $group).'.'.$key] = $value;
                }
            }
        }

        foreach ($this->namespacedJsonTranslations($files, $locale) as $key => $value) {
            $translations[$key] = $value;
        }

        foreach ($files->loadJson($locale) as $key => $value) {
            if (is_string($value)) {
                $translations[$key] = $value;
            }
        }

        return $translations;
    }

    /** @return array<int, string> */
    private function groupsForLocale(TranslationFileRepository $files, string $locale): array
    {
        $groups = $this->manifest->groups();

        if (! in_array('*', $groups, true)) {
            return $groups;
        }

        $directories = ['' => $files->langPath().DIRECTORY_SEPARATOR.$locale];
        $vendorPath = $files->langPath().DIRECTORY_SEPARATOR.'vendor';

        if (File::isDirectory($vendorPath)) {
            foreach (File::directories($vendorPath) as $namespacePath) {
                $directories[basename($namespacePath).'::'] = $namespacePath.DIRECTORY_SEPARATOR.$locale;
            }
        }

        $groups = [];

        foreach ($directories as $prefix => $directory) {
            if (! File::isDirectory($directory)) {
                continue;
            }

            foreach (File::allFiles($directory) as $file) {
                if ($file->getExtension() === 'php') {
                    $groups[] = $prefix.substr($file->getRelativePathname(), 0, -4);
                }
            }
        }

        sort($groups);

        return $groups;
    }

    /**
     * @return array<string, string>
     */
    private function namespacedJsonTranslations(TranslationFileRepository $files, string $locale): array
    {
        $vendorPath = $files->langPath().DIRECTORY_SEPARATOR.'vendor';

        if (! File::isDirectory($vendorPath)) {
            return [];
        }

        $translations = [];

        foreach (File::directories($vendorPath) as $namespacePath) {
            $namespace = basename($namespacePath);

            foreach ($files->loadJson($locale, $namespace) as $key => $value) {
                if (is_string($value)) {
                    $translations[$namespace.'::'.$key] = $value;
                }
            }
        }

        return $translations;
    }
}
