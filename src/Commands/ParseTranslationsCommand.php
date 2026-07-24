<?php

namespace KeypointSolutions\LaravelVox\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;
use KeypointSolutions\LaravelVox\Support\VoxFrontendManifest;
use KeypointSolutions\LaravelVox\Support\VoxKeyProtector;
use KeypointSolutions\LaravelVox\Support\VoxLocaleResolver;
use KeypointSolutions\LaravelVox\Translation\TranslationFileRepository;
use KeypointSolutions\LaravelVox\Translation\TranslationFileUpdater;
use KeypointSolutions\LaravelVox\Translation\TranslationFileWriter;
use KeypointSolutions\LaravelVox\Translation\TranslationKey;
use KeypointSolutions\LaravelVox\Translation\TranslationScanner;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class ParseTranslationsCommand extends Command
{
    public $signature = 'vox:parse';

    public $description = 'Parse codebase and update language files.';

    public function handle(): int
    {
        $langPath = rtrim(config('vox.paths.lang', lang_path()), DIRECTORY_SEPARATOR);

        if (! File::isDirectory($langPath)) {
            warning("Vox lang path not found: {$langPath}");
            warning('If you use a custom lang path, update vox.paths.lang in config/vox.php.');

            if (! $this->input->isInteractive()) {
                warning('Run "php artisan lang:publish" or create the directory before parsing.');

                return self::FAILURE;
            }

            $choice = select('Language files are missing. What should Vox do?', [
                'publish' => 'Run lang:publish and continue',
                'abort' => 'Abort',
            ]);

            if ($choice === 'abort') {
                return self::FAILURE;
            }

            $exitCode = spin(
                fn () => $this->call('lang:publish'),
                'Publishing language files'
            );

            if ($exitCode !== self::SUCCESS) {
                warning('lang:publish did not complete successfully.');

                return $exitCode;
            }

            if (! File::isDirectory($langPath)) {
                warning("Lang path still missing after publish: {$langPath}");

                return self::FAILURE;
            }
        }

        $scanner = new TranslationScanner(
            base_path(),
            config('vox.parse.paths', []),
            config('vox.parse.exclude', []),
            config('vox.parse.extensions', []),
            (int) config('vox.parse.context_lines', 3)
        );

        $scanResults = spin(fn () => $scanner->scan(), 'Scanning translation keys');
        app(VoxFrontendManifest::class)->writeFromScanResults($scanResults);

        if ($this->output->isVerbose()) {
            $this->outputAnalyzedFiles($scanner);
        }

        $localeResolver = new VoxLocaleResolver;
        $locales = $localeResolver->resolveLocales();
        $baseLocale = $localeResolver->resolveBaseLocale($locales);

        if (! in_array($baseLocale, $locales, true)) {
            $locales[] = $baseLocale;
        }

        $fileRepository = new TranslationFileRepository(new TranslationFileWriter);
        $beforeSnapshot = $this->snapshotLangFiles($fileRepository->langPath());
        $this->handleDynamicKeys($scanner->dynamicKeys(), $fileRepository, $baseLocale);
        $protector = new VoxKeyProtector(config('vox.parse.protected_keys', []));
        $updater = new TranslationFileUpdater($fileRepository, $protector);

        $result = $updater->updateFromScan($scanResults, $locales, $baseLocale);

        app(VoxAuditLogger::class)->record('parse', [
            'added' => $result->added(),
            'removed' => $result->removed(),
        ]);

        info('Translation files updated.');
        table(['Metric', 'Count'], [
            ['Added keys', (string) $result->added()],
            ['Removed keys', (string) $result->removed()],
        ]);
        $this->outputModifiedFiles(
            $fileRepository->langPath(),
            $beforeSnapshot,
            $this->snapshotLangFiles($fileRepository->langPath())
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array{prefix: string, suffix: string, source: string|null, is_frontend: bool, file: string, line: int|null, context: string|null}>  $dynamicKeys
     */
    private function handleDynamicKeys(array $dynamicKeys, TranslationFileRepository $fileRepository, string $baseLocale): void
    {
        if ($dynamicKeys === []) {
            return;
        }

        $protector = new VoxKeyProtector(config('vox.parse.protected_keys', []));
        $suggestions = $this->buildDynamicSuggestions($dynamicKeys, $protector);

        if ($suggestions === []) {
            return;
        }

        warning('Dynamic translation keys detected.');
        table(
            ['Prefix', 'Sample key', 'Source', 'Location'],
            array_map(
                fn (array $suggestion) => [
                    $this->formatDynamicDisplay($suggestion['prefix']),
                    $this->formatDynamicDisplay($suggestion['sample_full_key']),
                    $this->formatDynamicSource($suggestion),
                    $this->formatDynamicLocation($suggestion),
                ],
                $suggestions
            )
        );

        if (! $this->input->isInteractive()) {
            info('Run vox:parse interactively to add protected prefixes and sample keys.');

            return;
        }

        if (! confirm('Add these prefixes to protected keys and create sample entries?')) {
            return;
        }

        $this->appendProtectedPrefixes($suggestions);
        $this->addSampleEntries($suggestions, $fileRepository, $baseLocale);
    }

    /**
     * @return array<string, string>
     */
    private function snapshotLangFiles(string $langPath): array
    {
        if (! File::isDirectory($langPath)) {
            return [];
        }

        $snapshot = [];

        foreach (File::allFiles($langPath) as $file) {
            $extension = strtolower($file->getExtension());

            if (! in_array($extension, ['php', 'json'], true)) {
                continue;
            }

            $path = $file->getPathname();
            $contents = File::get($path);
            $snapshot[$path] = hash('sha256', $contents);
        }

        return $snapshot;
    }

    /**
     * @param  array<string, string>  $before
     * @param  array<string, string>  $after
     */
    private function outputModifiedFiles(string $langPath, array $before, array $after): void
    {
        $modified = [];

        foreach ($after as $path => $hash) {
            if (! isset($before[$path]) || $before[$path] !== $hash) {
                $modified[] = $this->formatLangPath($langPath, $path);
            }
        }

        if ($modified === []) {
            info('No translation files modified.');

            return;
        }

        table(['Modified files'], array_map(fn (string $path) => [$path], $modified));
    }

    private function formatLangPath(string $langPath, string $path): string
    {
        $prefix = rtrim($langPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (Str::startsWith($path, $prefix)) {
            return Str::replaceFirst($prefix, '', $path);
        }

        return $path;
    }

    /**
     * @param  array<int, array{prefix: string, suffix: string, source: string|null, is_frontend: bool, file: string, line: int|null, context: string|null}>  $dynamicKeys
     * @return array<int, array{prefix: string, sample_full_key: string, group: string|null, key: string, source: string|null, is_frontend: bool, file: string, line: int|null, context: string|null}>
     */
    private function buildDynamicSuggestions(array $dynamicKeys, VoxKeyProtector $protector): array
    {
        $suggestions = [];
        $seen = [];

        foreach ($dynamicKeys as $dynamic) {
            $prefix = $dynamic['prefix'];
            $suffix = $dynamic['suffix'];
            $sampleFullKey = $prefix.'VALUE'.$suffix;
            $translationKey = TranslationKey::fromRaw($sampleFullKey);
            $identifier = $prefix.'|'.$suffix;

            if (isset($seen[$identifier])) {
                continue;
            }

            if ($protector->isProtected($translationKey->key, $translationKey->group)) {
                continue;
            }

            $seen[$identifier] = true;

            $suggestions[] = [
                'prefix' => $prefix,
                'sample_full_key' => $sampleFullKey,
                'group' => $translationKey->group,
                'key' => $translationKey->key,
                'source' => $dynamic['source'],
                'is_frontend' => $dynamic['is_frontend'],
                'file' => $dynamic['file'],
                'line' => $dynamic['line'] ?? null,
                'context' => $dynamic['context'] ?? null,
            ];
        }

        return $suggestions;
    }

    private function formatDynamicDisplay(string $value): string
    {
        $collapsed = preg_replace('/\s+/', ' ', $value) ?? $value;
        $collapsed = trim($collapsed);

        return Str::limit($collapsed, 120, '...');
    }

    /**
     * @param  array{source: string|null, is_frontend: bool, context: string|null}  $suggestion
     */
    private function formatDynamicSource(array $suggestion): string
    {
        $context = $suggestion['context'];

        if (is_string($context) && $context !== '') {
            return $this->formatDynamicDisplay($context);
        }

        $source = $suggestion['source'] ?? ($suggestion['is_frontend'] ? 'frontend' : 'backend');

        return $this->formatDynamicDisplay((string) $source);
    }

    /**
     * @param  array{file: string, line: int|null}  $suggestion
     */
    private function formatDynamicLocation(array $suggestion): string
    {
        $location = $suggestion['file'];

        if (is_int($suggestion['line'])) {
            $location .= ':'.$suggestion['line'];
        }

        return $location;
    }

    private function outputAnalyzedFiles(TranslationScanner $scanner): void
    {
        $files = $scanner->files();

        if ($files === []) {
            info('No files analyzed.');

            return;
        }

        info('Analyzed files:');

        foreach ($files as $file) {
            $this->line($this->relativePath($file));
        }
    }

    private function relativePath(string $filePath): string
    {
        return ltrim(Str::replaceFirst(base_path(), '', $filePath), DIRECTORY_SEPARATOR);
    }

    /**
     * @param  array<int, array{prefix: string, sample_full_key: string, group: string|null, key: string, source: string|null, is_frontend: bool, file: string}>  $suggestions
     */
    private function appendProtectedPrefixes(array $suggestions): void
    {
        $prefixes = array_values(array_unique(array_map(
            fn (array $suggestion) => $suggestion['prefix'],
            $suggestions
        )));
        $existing = config('vox.parse.protected_keys', []);

        if (! is_array($existing)) {
            $existing = [];
        }

        $protected = array_values(array_unique(array_merge($existing, $prefixes)));
        config()->set('vox.parse.protected_keys', $protected);

        $configPath = config_path('vox.php');

        if (! File::exists($configPath)) {
            warning('Unable to update vox.php. Publish the config file to persist protected keys.');

            return;
        }

        $contents = File::get($configPath);
        $entry = "'protected_keys' => [\n";

        foreach ($protected as $key) {
            $sanitized = str_replace("'", "\\'", $key);
            $entry .= "            '".$sanitized."',\n";
        }

        $entry .= '        ],';

        $pattern = "/'protected_keys' => \\[[^\\]]*\\],/s";

        if (preg_match($pattern, $contents) === 1) {
            $contents = preg_replace($pattern, $entry, $contents, 1) ?? $contents;
        } elseif (str_contains($contents, "'protected_keys' => []")) {
            $contents = str_replace("'protected_keys' => []", $entry, $contents);
        }

        File::put($configPath, $contents);
    }

    /**
     * @param  array<int, array{prefix: string, sample_full_key: string, group: string|null, key: string, source: string|null, is_frontend: bool, file: string}>  $suggestions
     */
    private function addSampleEntries(array $suggestions, TranslationFileRepository $fileRepository, string $baseLocale): void
    {
        foreach ($suggestions as $suggestion) {
            if ($suggestion['group'] === null) {
                $existing = $fileRepository->loadJson($baseLocale);

                if (! array_key_exists($suggestion['key'], $existing)) {
                    $existing[$suggestion['key']] = $suggestion['key'];
                    $fileRepository->saveJson($baseLocale, $existing);
                }

                continue;
            }

            $existing = $fileRepository->loadGroup($baseLocale, $suggestion['group']);
            $flatExisting = Arr::dot($existing);

            if (array_key_exists($suggestion['key'], $flatExisting) || Arr::has($existing, $suggestion['key'])) {
                continue;
            }

            if (config('vox.parse.output', 'flat') === 'nested' || Arr::has($existing, $suggestion['key'])) {
                Arr::set($existing, $suggestion['key'], $this->sampleValueForKey($suggestion['key'], $suggestion['group']));
            } else {
                $existing[$suggestion['key']] = $this->sampleValueForKey($suggestion['key'], $suggestion['group']);
            }

            $fileRepository->saveGroup($baseLocale, $suggestion['group'], $existing);
        }
    }

    private function sampleValueForKey(string $key, ?string $group): string
    {
        if ($group === null) {
            return $key;
        }

        return $this->stripKeyPrefixes($key);
    }

    private function stripKeyPrefixes(string $key): string
    {
        $remaining = $key;

        while (true) {
            $dot = strpos($remaining, '.');

            if ($dot === false || $dot === strlen($remaining) - 1) {
                break;
            }

            $segment = substr($remaining, 0, $dot);

            if ($segment === '' || preg_match('/\\s/', $segment) === 1) {
                break;
            }

            $remaining = substr($remaining, $dot + 1);
        }

        return $remaining;
    }
}
