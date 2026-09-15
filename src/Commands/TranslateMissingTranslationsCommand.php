<?php

namespace KeypointSolutions\LaravelVox\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Support\TranslationFileChangeReporter;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;
use KeypointSolutions\LaravelVox\Support\VoxLocaleResolver;
use KeypointSolutions\LaravelVox\Translation\Drivers\OpenAiTranslationDriver;
use KeypointSolutions\LaravelVox\Translation\Drivers\TranslationDriver;
use KeypointSolutions\LaravelVox\Translation\Drivers\TranslationDriverFactory;
use KeypointSolutions\LaravelVox\Translation\TranslationEligibility;
use KeypointSolutions\LaravelVox\Translation\TranslationFileRepository;
use KeypointSolutions\LaravelVox\Translation\TranslationFileWriter;
use KeypointSolutions\LaravelVox\Translation\TranslationGroupFormat;
use KeypointSolutions\LaravelVox\Translation\TranslationPromptBuilder;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;

class TranslateMissingTranslationsCommand extends Command
{
    public $signature = 'vox:translate {--path= : Relative path inside the lang directory for a single file to process} {--key= : Translation key to process} {--force : Retranslate existing values}';

    public $description = 'Translate missing keys using the configured driver.';

    public function handle(TranslationEligibility $eligibility, TranslationGroupFormat $format): int
    {
        $localeResolver = app(VoxLocaleResolver::class);
        $locales = $localeResolver->resolveLocales();
        $baseLocale = $localeResolver->resolveBaseLocale($locales);

        if (! in_array($baseLocale, $locales, true)) {
            $locales[] = $baseLocale;
        }

        $fileRepository = new TranslationFileRepository(new TranslationFileWriter);
        $driver = app(TranslationDriverFactory::class)->make();
        $promptBuilder = app(TranslationPromptBuilder::class);
        $useContext = config('vox.translate.use_context', true);
        $force = (bool) $this->option('force');
        $requestedKey = $this->normalizeKeyOption((string) $this->option('key'));

        $translated = 0;
        $beforeSnapshot = app(TranslationFileChangeReporter::class)->snapshot($fileRepository->langPath());
        $target = $this->resolveTargetPath((string) $this->option('path'));
        $baseGroups = [];
        $baseJson = [];
        $vendorJsonNamespaces = [];
        $baseVendorJson = [];
        $baseGroupComments = [];

        if ($target === null) {
            $baseGroups = $this->collectGroups($fileRepository, $baseLocale);
            $baseJson = $fileRepository->loadJson($baseLocale);
            $vendorJsonNamespaces = $this->collectVendorJsonNamespaces($fileRepository, $baseLocale);

            if ($useContext) {
                foreach (array_keys($baseGroups) as $group) {
                    $baseGroupComments[$group] = $fileRepository->loadLineComments($baseLocale, $group);
                }
            }

            foreach ($vendorJsonNamespaces as $namespace) {
                $baseVendorJson[$namespace] = $fileRepository->loadJson($baseLocale, $namespace);
            }
        } elseif ($target['type'] === 'group') {
            $path = $fileRepository->groupPath($baseLocale, $target['group']);

            if (! File::exists($path)) {
                $this->error("Base locale file not found: {$path}");

                return self::FAILURE;
            }

            $entries = $fileRepository->loadGroup($baseLocale, $target['group']);
            $baseGroups[$target['group']] = Arr::dot($entries);

            if ($useContext) {
                $baseGroupComments[$target['group']] = $fileRepository->loadLineComments($baseLocale, $target['group']);
            }
        } else {
            $path = $fileRepository->jsonPath($baseLocale, $target['namespace']);

            if (! File::exists($path)) {
                $this->error("Base locale file not found: {$path}");

                return self::FAILURE;
            }

            if ($target['namespace'] !== null) {
                $vendorJsonNamespaces = [$target['namespace']];
                $baseVendorJson[$target['namespace']] = $fileRepository->loadJson($baseLocale, $target['namespace']);
            } else {
                $baseJson = $fileRepository->loadJson($baseLocale);
            }
        }

        [$keyFilter, $groupFilter, $jsonNamespaceFilter] = $this->resolveKeyFilters($requestedKey, $target, $baseGroups);

        if (! $this->hasRequestedKey($baseGroups, $baseJson, $baseVendorJson, $target, $keyFilter, $groupFilter, $jsonNamespaceFilter)) {
            $this->error('Translation key not found in base locale.');

            return self::FAILURE;
        }

        foreach ($locales as $locale) {
            if ($locale === $baseLocale) {
                continue;
            }

            foreach ($baseGroups as $group => $entries) {
                if (! $this->shouldTranslateGroup($group, $groupFilter)) {
                    continue;
                }

                $existing = $fileRepository->loadGroup($locale, $group);

                $existing = $format->normalize($existing);

                $flatExisting = Arr::dot($existing);
                $updated = $existing;

                foreach ($entries as $key => $value) {
                    if (! $this->shouldTranslateKey($key, $keyFilter)) {
                        continue;
                    }

                    $current = $flatExisting[$key] ?? Arr::get($existing, $key);

                    if ($current !== null && ! is_string($current)) {
                        continue;
                    }

                    if (! $force && ! $eligibility->isMissing($current)) {
                        continue;
                    }

                    if (! is_string($value)) {
                        continue;
                    }

                    if (! $eligibility->canTranslateSource($key, $value)) {
                        continue;
                    }

                    $translation = $this->translateValue(
                        $driver,
                        $promptBuilder,
                        (string) $value,
                        $baseLocale,
                        $locale,
                        "{$group}.{$key}",
                        $this->buildTranslationContext($baseGroupComments[$group] ?? [], $key)
                    );
                    $format->set($updated, $key, $translation);
                    $translated++;
                }

                $lineComments = $fileRepository->loadLineComments($locale, $group);
                $rawCommented = $fileRepository->loadObsoleteComments($locale, $group);

                $fileRepository->saveGroup($locale, $group, $updated, [], $lineComments, array_values($rawCommented));
            }

            if ($groupFilter === null) {
                $targetJson = $fileRepository->loadJson($locale);
                $updatedJson = $targetJson;

                foreach ($baseJson as $key => $value) {
                    if (! $this->shouldTranslateJsonKey(null, $key, $keyFilter, $jsonNamespaceFilter)) {
                        continue;
                    }

                    $current = $targetJson[$key] ?? null;

                    if ($current !== null && ! is_string($current)) {
                        continue;
                    }

                    if (! $force && ! $eligibility->isMissing($current)) {
                        continue;
                    }

                    if (! is_string($value)) {
                        continue;
                    }

                    if (! $eligibility->canTranslateSource($key, $value)) {
                        continue;
                    }

                    $translation = $this->translateValue(
                        $driver,
                        $promptBuilder,
                        (string) $value,
                        $baseLocale,
                        $locale,
                        $key
                    );
                    $updatedJson[$key] = $translation;
                    $translated++;
                }

                $fileRepository->saveJson($locale, $updatedJson);

                foreach ($baseVendorJson as $namespace => $entries) {
                    if (! $this->shouldTranslateVendorJsonNamespace($namespace, $jsonNamespaceFilter)) {
                        continue;
                    }

                    $targetJson = $fileRepository->loadJson($locale, $namespace);
                    $updatedJson = $targetJson;

                    foreach ($entries as $key => $value) {
                        if (! $this->shouldTranslateJsonKey($namespace, $key, $keyFilter, $jsonNamespaceFilter)) {
                            continue;
                        }

                        $current = $targetJson[$key] ?? null;

                        if ($current !== null && ! is_string($current)) {
                            continue;
                        }

                        if (! $force && ! $eligibility->isMissing($current)) {
                            continue;
                        }

                        if (! is_string($value)) {
                            continue;
                        }

                        if (! $eligibility->canTranslateSource($key, $value)) {
                            continue;
                        }

                        $translation = $this->translateValue(
                            $driver,
                            $promptBuilder,
                            (string) $value,
                            $baseLocale,
                            $locale,
                            "{$namespace}::{$key}"
                        );
                        $updatedJson[$key] = $translation;
                        $translated++;
                    }

                    $fileRepository->saveJson($locale, $updatedJson, $namespace);
                }
            }
        }

        app(VoxAuditLogger::class)->record('translate', [
            'translated' => $translated,
        ]);

        info('Translations updated.');
        table(['Metric', 'Count'], [
            ['Translated keys', (string) $translated],
        ]);
        app(TranslationFileChangeReporter::class)->report(
            $fileRepository->langPath(),
            $beforeSnapshot,
            app(TranslationFileChangeReporter::class)->snapshot($fileRepository->langPath())
        );

        return self::SUCCESS;
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
     * @param  array<string, mixed>  $context
     */
    private function translateValue(
        TranslationDriver $driver,
        TranslationPromptBuilder $promptBuilder,
        string $text,
        string $sourceLocale,
        string $targetLocale,
        string $label,
        array $context = []
    ): string {
        $displayLabel = $this->formatLabelForOutput($label);

        if (! $this->output->isVerbose()) {
            return spin(
                fn () => $driver->translate($text, $sourceLocale, $targetLocale, $context),
                "Translating {$displayLabel} to {$targetLocale}"
            );
        }

        $this->output->writeln('');
        $this->output->writeln("AI translation: {$displayLabel}");

        $rows = [];

        if ($driver instanceof OpenAiTranslationDriver) {
            $systemPrompt = $promptBuilder->build($text, $sourceLocale, $targetLocale, $context);
            $rows[] = ['system', $systemPrompt];
        } else {
            $rows[] = ['note', 'Translation driver does not expose AI message payloads.'];
        }

        $rows[] = ['user', $text];

        $translation = $driver->translate($text, $sourceLocale, $targetLocale, $context);

        $rows[] = ['assistant', $translation];

        table(['Role', 'Content'], $this->formatPromptRows($rows));

        return $translation;
    }

    private function formatLabelForOutput(string $label): string
    {
        $collapsed = preg_replace('/\s+/', ' ', $label);
        $collapsed = trim((string) $collapsed);

        if ($collapsed === '') {
            return $collapsed;
        }

        return Str::limit($collapsed, 120, '...');
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $rows
     * @return array<int, array<int, string>>
     */
    private function formatPromptRows(array $rows): array
    {
        $columns = getenv('COLUMNS');
        $maxWidth = (is_string($columns) && ctype_digit($columns)) ? (int) $columns : 120;
        $contentWidth = max(40, $maxWidth - 20);
        $formatted = [];

        foreach ($rows as $row) {
            [$role, $content] = $row;
            $lines = $this->wrapContent($content, $contentWidth);

            foreach ($lines as $index => $line) {
                $formatted[] = [$index === 0 ? $role : '', $line];
            }
        }

        return $formatted;
    }

    /**
     * @return array<int, string>
     */
    private function wrapContent(string $content, int $width): array
    {
        $content = str_replace("\r\n", "\n", $content);
        $parts = explode("\n", $content);
        $lines = [];

        foreach ($parts as $part) {
            if ($part === '') {
                $lines[] = '';

                continue;
            }

            $wrapped = wordwrap($part, $width, "\n", true);
            $lines = array_merge($lines, explode("\n", $wrapped));
        }

        return $lines === [] ? [''] : $lines;
    }

    private function normalizeKeyOption(string $value): ?string
    {
        $normalized = trim($value);

        if ($normalized === '') {
            return null;
        }

        return $normalized;
    }

    /**
     * @param  array<string, array<string, string>>  $baseGroups
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function resolveKeyFilters(?string $requestedKey, ?array $target, array $baseGroups): array
    {
        if ($requestedKey === null) {
            return [null, null, null];
        }

        $keyFilter = $requestedKey;
        $groupFilter = null;
        $jsonNamespaceFilter = null;

        if ($target !== null) {
            if ($target['type'] === 'group') {
                $keyFilter = $this->stripGroupPrefix($requestedKey, $target['group']);
            } else {
                $keyFilter = $this->stripJsonNamespacePrefix($requestedKey, $target['namespace']);
            }

            return [$keyFilter, $groupFilter, $jsonNamespaceFilter];
        }

        if (Str::contains($requestedKey, '::')) {
            [$namespace, $rest] = explode('::', $requestedKey, 2);
            $rest = ltrim($rest, '.');

            if ($rest !== '' && Str::contains($rest, '.')) {
                [$groupName, $restKey] = explode('.', $rest, 2);
                $candidateGroup = $namespace.'::'.$groupName;

                if (array_key_exists($candidateGroup, $baseGroups)) {
                    return [$restKey, $candidateGroup, null];
                }
            }

            return [$rest, null, $namespace];
        }

        if (Str::contains($requestedKey, '.')) {
            [$groupName, $restKey] = explode('.', $requestedKey, 2);

            if (array_key_exists($groupName, $baseGroups)) {
                return [$restKey, $groupName, null];
            }
        }

        return [$keyFilter, $groupFilter, $jsonNamespaceFilter];
    }

    private function stripGroupPrefix(string $key, string $group): string
    {
        $prefixes = [$group.'.'];
        $plainGroup = Str::contains($group, '::') ? Str::after($group, '::') : null;

        if ($plainGroup !== null && $plainGroup !== '') {
            $prefixes[] = $plainGroup.'.';
        }

        foreach ($prefixes as $prefix) {
            if (Str::startsWith($key, $prefix)) {
                return substr($key, strlen($prefix));
            }
        }

        return $key;
    }

    private function stripJsonNamespacePrefix(string $key, ?string $namespace): string
    {
        if ($namespace === null) {
            return $key;
        }

        $prefix = $namespace.'::';

        if (Str::startsWith($key, $prefix)) {
            return substr($key, strlen($prefix));
        }

        return $key;
    }

    private function shouldTranslateGroup(string $group, ?string $groupFilter): bool
    {
        if ($groupFilter === null) {
            return true;
        }

        return $group === $groupFilter;
    }

    private function shouldTranslateVendorJsonNamespace(string $namespace, ?string $namespaceFilter): bool
    {
        if ($namespaceFilter === null) {
            return true;
        }

        return $namespace === $namespaceFilter;
    }

    private function shouldTranslateKey(string $key, ?string $keyFilter): bool
    {
        if ($keyFilter === null) {
            return true;
        }

        return $key === $keyFilter;
    }

    private function shouldTranslateJsonKey(?string $namespace, string $key, ?string $keyFilter, ?string $namespaceFilter): bool
    {
        if ($keyFilter === null) {
            return true;
        }

        if ($namespaceFilter !== null && $namespace !== $namespaceFilter) {
            return false;
        }

        return $key === $keyFilter;
    }

    /**
     * @param  array<string, array<string, string>>  $baseGroups
     * @param  array<string, string>  $baseJson
     * @param  array<string, array<string, string>>  $baseVendorJson
     */
    private function hasRequestedKey(
        array $baseGroups,
        array $baseJson,
        array $baseVendorJson,
        ?array $target,
        ?string $keyFilter,
        ?string $groupFilter,
        ?string $jsonNamespaceFilter
    ): bool {
        if ($keyFilter === null) {
            return true;
        }

        if ($target !== null) {
            if ($target['type'] === 'group') {
                $entries = $baseGroups[$target['group']] ?? [];

                return array_key_exists($keyFilter, $entries);
            }

            $namespace = $target['namespace'];

            if ($namespace !== null) {
                $entries = $baseVendorJson[$namespace] ?? [];

                return array_key_exists($keyFilter, $entries);
            }

            return array_key_exists($keyFilter, $baseJson);
        }

        if ($groupFilter !== null) {
            $entries = $baseGroups[$groupFilter] ?? [];

            if (array_key_exists($keyFilter, $entries)) {
                return true;
            }
        } else {
            foreach ($baseGroups as $entries) {
                if (array_key_exists($keyFilter, $entries)) {
                    return true;
                }
            }
        }

        if ($jsonNamespaceFilter !== null) {
            $entries = $baseVendorJson[$jsonNamespaceFilter] ?? [];

            if (array_key_exists($keyFilter, $entries)) {
                return true;
            }
        } else {
            if (array_key_exists($keyFilter, $baseJson)) {
                return true;
            }

            foreach ($baseVendorJson as $entries) {
                if (array_key_exists($keyFilter, $entries)) {
                    return true;
                }
            }
        }

        return false;
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
