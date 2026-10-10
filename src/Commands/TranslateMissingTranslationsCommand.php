<?php

namespace KeypointSolutions\LaravelVox\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Ai\AiAvailability;
use KeypointSolutions\LaravelVox\Ai\TranslationPromptBuilder;
use KeypointSolutions\LaravelVox\Commands\Concerns\RecordsTranslationCheckpoint;
use KeypointSolutions\LaravelVox\Commands\Concerns\RendersAiTranslationOutput;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;
use KeypointSolutions\LaravelVox\Translation\Drivers\TranslationDriver;
use KeypointSolutions\LaravelVox\Translation\Drivers\TranslationDriverFactory;
use KeypointSolutions\LaravelVox\Translation\Files\TranslationFileChangeReporter;
use KeypointSolutions\LaravelVox\Translation\Files\TranslationFileRepository;
use KeypointSolutions\LaravelVox\Translation\Files\TranslationFileWriter;
use KeypointSolutions\LaravelVox\Translation\Files\TranslationGroupFormat;
use KeypointSolutions\LaravelVox\Translation\Locales\VoxLocaleResolver;
use KeypointSolutions\LaravelVox\Translation\TranslationEligibility;
use KeypointSolutions\LaravelVox\Translation\TranslationFallbackRules;
use KeypointSolutions\LaravelVox\Translation\TranslationKeyFilter;
use Throwable;

use function Laravel\Prompts\info;
use function Laravel\Prompts\table;

class TranslateMissingTranslationsCommand extends Command
{
    use RecordsTranslationCheckpoint;
    use RendersAiTranslationOutput;

    public $signature = 'vox:translate {--path= : Relative path inside the lang directory for a single file to process} {--key= : Translation key to process} {--force : Retranslate existing wording; deliberately blank values are kept}';

    public $description = 'Translate missing keys using the configured driver.';

    private TranslationFileRepository $files;

    private TranslationDriver $driver;

    private TranslationPromptBuilder $promptBuilder;

    private TranslationEligibility $eligibility;

    private TranslationGroupFormat $format;

    private string $baseLocale;

    private bool $force = false;

    private int $translated = 0;

    private ?Throwable $failure = null;

    public function handle(TranslationEligibility $eligibility, TranslationGroupFormat $format): int
    {
        if (($reason = app(AiAvailability::class)->reason()) !== null) {
            $this->error($reason);

            return self::FAILURE;
        }

        [$locales, $this->baseLocale] = app(VoxLocaleResolver::class)->resolveLocalesWithBase();

        $this->files = new TranslationFileRepository(new TranslationFileWriter);
        $this->driver = app(TranslationDriverFactory::class)->make();
        $this->promptBuilder = app(TranslationPromptBuilder::class);
        $this->eligibility = $eligibility;
        $this->format = $format;
        $this->force = (bool) $this->option('force');
        $this->translated = 0;
        $this->failure = null;

        $requestedKey = trim((string) $this->option('key'));
        $beforeSnapshot = app(TranslationFileChangeReporter::class)->snapshot($this->files->langPath());
        $target = $this->resolveTargetPath((string) $this->option('path'));
        $base = $this->loadBaseTranslations($target);

        if ($base === null) {
            return self::FAILURE;
        }

        $filter = TranslationKeyFilter::resolve($requestedKey === '' ? null : $requestedKey, $target, $base['groups']);

        if (! $filter->matchesAny($base['groups'], $base['json'], $base['vendorJson'], $target)) {
            $this->error('Translation key not found in base locale.');

            return self::FAILURE;
        }

        foreach ($locales as $locale) {
            if ($locale === $this->baseLocale) {
                continue;
            }

            foreach ($base['groups'] as $group => $entries) {
                if ($this->failure === null && $filter->allowsGroup($group)) {
                    $this->translateGroup($locale, $group, $entries, $base['comments'][$group] ?? [], $filter);
                }
            }

            if ($this->failure !== null) {
                break;
            }

            if ($filter->group !== null) {
                continue;
            }

            $this->translateJson($locale, null, $base['json'], $filter);

            foreach ($base['vendorJson'] as $namespace => $entries) {
                if ($this->failure === null && $filter->allowsVendorJsonNamespace($namespace)) {
                    $this->translateJson($locale, $namespace, $entries, $filter);
                }
            }

            if ($this->failure !== null) {
                break;
            }
        }

        app(VoxAuditLogger::class)->record('translate', [
            'translated' => $this->translated,
        ]);

        info('Translations updated.');
        table(['Metric', 'Count'], [
            ['Translated keys', (string) $this->translated],
        ]);
        app(TranslationFileChangeReporter::class)->report(
            $this->files->langPath(),
            $beforeSnapshot,
            app(TranslationFileChangeReporter::class)->snapshot($this->files->langPath())
        );

        if ($this->failure !== null) {
            $this->error('Stopped early: '.$this->failure->getMessage());
            $this->line('Translations completed before this point were saved. Run the command again to continue.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Load the base locale entries for the whole lang directory, or only for the targeted file.
     *
     * @param  array{type: string, group?: string, namespace?: string|null}|null  $target
     * @return array{groups: array<string, array<string, mixed>>, json: array<string, mixed>, vendorJson: array<string, array<string, mixed>>, comments: array<string, array<string, array<int, string>>>}|null
     */
    private function loadBaseTranslations(?array $target): ?array
    {
        $useContext = config('vox.translate.use_context', true);
        $base = ['groups' => [], 'json' => [], 'vendorJson' => [], 'comments' => []];

        if ($target === null) {
            $base['groups'] = $this->collectGroups($this->files, $this->baseLocale);
            $base['json'] = $this->files->loadJson($this->baseLocale);

            foreach ($this->collectVendorJsonNamespaces($this->files, $this->baseLocale) as $namespace) {
                $base['vendorJson'][$namespace] = $this->files->loadJson($this->baseLocale, $namespace);
            }
        } elseif ($target['type'] === 'group') {
            if (! $this->baseFileExists($this->files->groupPath($this->baseLocale, $target['group']))) {
                return null;
            }

            $base['groups'][$target['group']] = Arr::dot($this->files->loadGroup($this->baseLocale, $target['group']));
        } else {
            if (! $this->baseFileExists($this->files->jsonPath($this->baseLocale, $target['namespace']))) {
                return null;
            }

            if ($target['namespace'] !== null) {
                $base['vendorJson'][$target['namespace']] = $this->files->loadJson($this->baseLocale, $target['namespace']);
            } else {
                $base['json'] = $this->files->loadJson($this->baseLocale);
            }
        }

        if ($useContext) {
            foreach (array_keys($base['groups']) as $group) {
                $base['comments'][$group] = $this->files->loadLineComments($this->baseLocale, $group);
            }
        }

        return $base;
    }

    private function baseFileExists(string $path): bool
    {
        if (File::exists($path)) {
            return true;
        }

        $this->error("Base locale file not found: {$path}");

        return false;
    }

    /**
     * @param  array<string, mixed>  $entries
     * @param  array<string, array<int, string>>  $baseComments
     */
    private function translateGroup(string $locale, string $group, array $entries, array $baseComments, TranslationKeyFilter $filter): void
    {
        $existing = $this->format->normalize($this->files->loadGroup($locale, $group));
        $flatExisting = Arr::dot($existing);
        $updated = $existing;

        foreach ($entries as $key => $value) {
            if (! $filter->allowsKey($key)) {
                continue;
            }

            $current = $flatExisting[$key] ?? Arr::get($existing, $key);

            if (! $this->needsTranslation($locale, $group, $key, $key, $current, $value)) {
                continue;
            }

            try {
                $this->format->set($updated, $key, $this->translateValue(
                    $value,
                    $locale,
                    "{$group}.{$key}",
                    $this->buildTranslationContext($baseComments, $key)
                ));
            } catch (Throwable $exception) {
                $this->failure = $exception;

                break;
            }
        }

        $this->files->saveGroup(
            $locale,
            $group,
            $updated,
            [],
            $this->files->loadLineComments($locale, $group),
            array_values($this->files->loadObsoleteComments($locale, $group))
        );
    }

    /**
     * @param  array<string, mixed>  $entries
     */
    private function translateJson(string $locale, ?string $namespace, array $entries, TranslationKeyFilter $filter): void
    {
        $existing = $this->files->loadJson($locale, $namespace);
        $updated = $existing;

        foreach ($entries as $key => $value) {
            if (! $filter->allowsJsonKey($namespace, $key)) {
                continue;
            }

            $fullKey = $namespace === null ? $key : "{$namespace}::{$key}";

            if (! $this->needsTranslation($locale, 'json', $fullKey, $key, $existing[$key] ?? null, $value)) {
                continue;
            }

            try {
                $updated[$key] = $this->translateValue($value, $locale, $fullKey);
            } catch (Throwable $exception) {
                $this->failure = $exception;

                break;
            }
        }

        $this->files->saveJson($locale, $updated, $namespace);
    }

    /**
     * Whether a target value should be (re)translated from the given source value.
     */
    private function needsTranslation(string $locale, string $group, string $ruleKey, string $key, mixed $current, mixed $value): bool
    {
        $fallbackRules = app(TranslationFallbackRules::class);

        if ($fallbackRules->usesDefault($locale, $group, $ruleKey) || $fallbackRules->usesDefault($locale, $group, $ruleKey, true)) {
            return false;
        }

        if (($current !== null && ! is_string($current)) || $this->eligibility->isBlank($current)) {
            return false;
        }

        if (! $this->force && ! $this->eligibility->isMissing($current)) {
            return false;
        }

        return is_string($value) && $this->eligibility->canTranslateSource($key, $value);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function translateValue(string $text, string $locale, string $label, array $context = []): string
    {
        $translation = $this->translateWithOutput($this->driver, $this->promptBuilder, $text, $this->baseLocale, $locale, $label, $context);
        $this->translated++;

        return $translation;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function collectGroups(TranslationFileRepository $files, string $locale): array
    {
        $groups = [];
        foreach ($files->groups($locale) as $group) {
            $groups[$group] = Arr::dot($files->loadGroup($locale, $group));
        }

        return $groups;
    }

    /** @return array<int, string> */
    private function collectVendorJsonNamespaces(TranslationFileRepository $files, string $locale): array
    {
        return array_values(array_filter($files->jsonNamespaces($locale), fn ($namespace): bool => $namespace !== null));
    }

    private function resolveTargetPath(string $path): ?array
    {
        $normalized = trim($path);

        if ($normalized === '') {
            return null;
        }

        $normalized = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $normalized);
        $normalized = ltrim($normalized, DIRECTORY_SEPARATOR);
        $segments = array_values(array_filter(explode(DIRECTORY_SEPARATOR, $normalized), fn (string $part) => $part !== ''));

        if ($segments === []) {
            return null;
        }

        $filename = $segments[count($segments) - 1];

        if (Str::endsWith($filename, '.php')) {
            $offset = ($segments[0] ?? null) === 'vendor' ? 3 : 1;
            $relative = count($segments) > $offset ? implode('/', array_slice($segments, $offset)) : $filename;
            $group = substr($relative, 0, -4);

            if ($group === '') {
                return null;
            }

            if (($segments[0] ?? null) === 'vendor') {
                $namespace = $segments[1] ?? null;

                if (! is_string($namespace) || $namespace === '') {
                    return null;
                }

                $group = $namespace.'::'.$group;
            }

            return ['type' => 'group', 'group' => $group];
        }

        if (Str::endsWith($filename, '.json')) {
            $namespace = null;

            if (($segments[0] ?? null) === 'vendor') {
                $namespace = $segments[1] ?? null;

                if (! is_string($namespace) || $namespace === '') {
                    return null;
                }
            }

            return ['type' => 'json', 'namespace' => $namespace];
        }

        return null;
    }

    /**
     * @param  array<string, array<int, string>>  $lineComments
     * @return array<string, string>
     */
    private function buildTranslationContext(array $lineComments, string $key): array
    {
        if (! config('vox.translate.use_context', true)) {
            return [];
        }

        $lines = $lineComments[$key] ?? null;

        if (! is_array($lines)) {
            return [];
        }

        foreach ($lines as $line) {
            if (! is_string($line) || $line === '') {
                continue;
            }

            if (str_contains($line, 'KEY')) {
                return ['context' => $line];
            }
        }

        return [];
    }
}
