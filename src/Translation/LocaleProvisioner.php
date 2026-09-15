<?php

namespace KeypointSolutions\LaravelVox\Translation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;
use KeypointSolutions\LaravelVox\Support\VoxLocaleResolver;
use KeypointSolutions\LaravelVox\Support\VoxSettingsRepository;
use KeypointSolutions\LaravelVox\Translation\Drivers\TranslationDriver;
use KeypointSolutions\LaravelVox\Translation\Drivers\TranslationDriverFactory;
use RuntimeException;
use Throwable;

class LocaleProvisioner
{
    public function __construct(
        private TranslationFileRepository $files,
        private TranslationDatabaseSynchronizer $synchronizer,
        private TranslationDriverFactory $driverFactory,
        private FrontendTranslationArtifacts $frontendArtifacts,
        private VoxLocaleResolver $localeResolver,
        private VoxSettingsRepository $settings,
        private VoxAuditLogger $auditLogger,
    ) {}

    public function provision(string $locale, bool $autoTranslate = false): LocaleProvisionResult
    {
        $locale = $this->localeResolver->normalizeLocaleCode($locale);

        if ($locale === '') {
            throw new RuntimeException('Enter a valid locale code such as de, pt_BR, or zh_Hant.');
        }

        if ($this->localeResolver->resolveLocale($locale) !== null) {
            throw new RuntimeException("Locale [{$locale}] is already defined.");
        }

        $locales = $this->localeResolver->resolveLocales();
        $baseLocale = $this->localeResolver->resolveBaseLocale($locales);
        $groups = $this->files->groups($baseLocale);
        $jsonNamespaces = $this->files->jsonNamespaces($baseLocale);

        if ($groups === [] && $jsonNamespaces === []) {
            throw new RuntimeException("The source locale [{$baseLocale}] does not contain any PHP or JSON translation files.");
        }

        $this->assertTargetIsEmpty($locale, $groups, $jsonNamespaces);

        $stagingPath = storage_path('vox/provision-'.Str::uuid());
        $stagedFiles = $this->files->forPath($stagingPath);
        $driver = $autoTranslate ? $this->driverFactory->make() : null;
        $valueCount = 0;
        $translatedValueCount = 0;
        $installedFiles = [];
        $runtimeArtifactPath = $this->frontendArtifacts->pathForLocale($locale);
        $previousRuntimeArtifact = File::isFile($runtimeArtifactPath)
            ? File::get($runtimeArtifactPath)
            : null;

        File::ensureDirectoryExists($stagingPath);

        try {
            foreach ($groups as $group) {
                $values = $this->prepareValues(
                    $this->files->loadGroup($baseLocale, $group),
                    $group,
                    $baseLocale,
                    $locale,
                    $driver,
                    $valueCount,
                    $translatedValueCount,
                );

                $stagedFiles->saveGroup(
                    $locale,
                    $group,
                    app(TranslationGroupFormat::class)->normalize($values),
                    [],
                    $this->files->loadLineComments($baseLocale, $group),
                    array_values($this->files->loadObsoleteComments($baseLocale, $group))
                );
            }

            foreach ($jsonNamespaces as $namespace) {
                $label = $namespace === null ? 'json' : $namespace.'::json';
                $values = $this->prepareValues(
                    $this->files->loadJson($baseLocale, $namespace),
                    $label,
                    $baseLocale,
                    $locale,
                    $driver,
                    $valueCount,
                    $translatedValueCount,
                );

                $stagedFiles->saveJson($locale, $values, $namespace);
            }

            $stagedPaths = collect(File::allFiles($stagingPath))
                ->map(fn (\SplFileInfo $file): string => $file->getPathname())
                ->values()
                ->all();

            DB::connection(config('vox.database.connection', 'vox'))
                ->transaction(function () use (
                    $stagedPaths,
                    $stagingPath,
                    $locale,
                    $baseLocale,
                    $autoTranslate,
                    $valueCount,
                    $translatedValueCount,
                    &$installedFiles,
                ): void {
                    foreach ($stagedPaths as $stagedPath) {
                        $relativePath = substr(
                            $stagedPath,
                            strlen(rtrim($stagingPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
                        );
                        $destination = $this->files->langPath().DIRECTORY_SEPARATOR.$relativePath;

                        if (File::exists($destination)) {
                            throw new RuntimeException("Locale file [{$relativePath}] already exists.");
                        }

                        File::ensureDirectoryExists(dirname($destination));

                        if (! File::copy($stagedPath, $destination)) {
                            throw new RuntimeException("Unable to create locale file [{$relativePath}].");
                        }

                        $installedFiles[] = $destination;
                    }

                    $provisionedLocales = array_values(array_unique(array_merge(
                        $this->settings->provisionedLocales(),
                        [$locale]
                    )));

                    if (! $this->settings->save(['provisioned_locales' => $provisionedLocales])) {
                        throw new RuntimeException(
                            'Settings storage is unavailable. Run the Laravel Vox migrations and try again.'
                        );
                    }

                    $syncResult = $this->synchronizer->sync();

                    $this->auditLogger->record('locale-provisioned', [
                        'locale' => $locale,
                        'source_locale' => $baseLocale,
                        'files' => count($stagedPaths),
                        'values' => $valueCount,
                        'translated_values' => $translatedValueCount,
                        'auto_translated' => $autoTranslate,
                        'reopened_translations' => $syncResult->reopenedTranslations(),
                    ]);
                });

            return new LocaleProvisionResult(
                $locale,
                count($stagedPaths),
                $valueCount,
                $translatedValueCount,
            );
        } catch (Throwable $exception) {
            File::delete($installedFiles);
            $this->removeTargetDirectories($locale, $groups);

            if ($previousRuntimeArtifact === null) {
                File::delete($runtimeArtifactPath);
            } else {
                File::ensureDirectoryExists(dirname($runtimeArtifactPath));
                File::put($runtimeArtifactPath, $previousRuntimeArtifact);
            }

            throw $exception;
        } finally {
            File::deleteDirectory($stagingPath);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function prepareValues(
        array $values,
        string $group,
        string $sourceLocale,
        string $targetLocale,
        ?TranslationDriver $driver,
        int &$valueCount,
        int &$translatedValueCount,
        string $parentKey = '',
    ): array {
        $prepared = [];
        $missingPrefix = (string) config('vox.parse.missing_translation_prefix', '🚩');

        foreach ($values as $key => $value) {
            $key = (string) $key;
            $fullKey = $parentKey === '' ? $key : $parentKey.'.'.$key;

            if (is_array($value)) {
                $prepared[$key] = $this->prepareValues(
                    $value,
                    $group,
                    $sourceLocale,
                    $targetLocale,
                    $driver,
                    $valueCount,
                    $translatedValueCount,
                    $fullKey,
                );

                continue;
            }

            if (! is_string($value) || $value === '') {
                $prepared[$key] = $value;

                continue;
            }

            $valueCount++;

            if ($driver === null || ! app(TranslationEligibility::class)->canTranslateSource($fullKey, $value)) {
                $prepared[$key] = Str::startsWith($value, $missingPrefix)
                    ? $value
                    : $missingPrefix.$value;

                continue;
            }

            $prepared[$key] = $driver->translate(
                $value,
                $sourceLocale,
                $targetLocale,
                ['context' => "Translation key: {$group}.{$fullKey}"]
            );
            $translatedValueCount++;
        }

        return $prepared;
    }

    /**
     * @param  array<int, string>  $groups
     * @param  array<int, string|null>  $jsonNamespaces
     */
    private function assertTargetIsEmpty(string $locale, array $groups, array $jsonNamespaces): void
    {
        $localePath = $this->files->langPath().DIRECTORY_SEPARATOR.$locale;

        if (File::exists($localePath) || File::exists($this->files->jsonPath($locale))) {
            throw new RuntimeException("Locale [{$locale}] already has language files.");
        }

        foreach ($groups as $group) {
            if (File::exists($this->files->groupPath($locale, $group))) {
                throw new RuntimeException("Locale [{$locale}] already has language files.");
            }
        }

        foreach ($jsonNamespaces as $namespace) {
            if (File::exists($this->files->jsonPath($locale, $namespace))) {
                throw new RuntimeException("Locale [{$locale}] already has language files.");
            }
        }
    }

    /**
     * @param  array<int, string>  $groups
     */
    private function removeTargetDirectories(string $locale, array $groups): void
    {
        $localePath = $this->files->langPath().DIRECTORY_SEPARATOR.$locale;

        if (File::isDirectory($localePath)) {
            File::deleteDirectory($localePath);
        }

        foreach ($groups as $group) {
            if (! str_contains($group, '::')) {
                continue;
            }

            [$namespace] = explode('::', $group, 2);
            $path = $this->files->langPath().DIRECTORY_SEPARATOR.'vendor'
                .DIRECTORY_SEPARATOR.$namespace.DIRECTORY_SEPARATOR.$locale;

            if (File::isDirectory($path)) {
                File::deleteDirectory($path);
            }
        }
    }
}
