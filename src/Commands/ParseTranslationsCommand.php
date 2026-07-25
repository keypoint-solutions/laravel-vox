<?php

namespace KeypointSolutions\LaravelVox\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;
use KeypointSolutions\LaravelVox\Support\VoxDynamicKeyRegistry;
use KeypointSolutions\LaravelVox\Support\VoxFrontendManifest;
use KeypointSolutions\LaravelVox\Support\VoxLocaleResolver;
use KeypointSolutions\LaravelVox\Translation\TranslationFileRepository;
use KeypointSolutions\LaravelVox\Translation\TranslationFileUpdater;
use KeypointSolutions\LaravelVox\Translation\TranslationFileWriter;
use KeypointSolutions\LaravelVox\Translation\TranslationScanner;

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
        $dynamicKeys = $scanner->dynamicKeys();
        $dynamicKeyRegistry = app(VoxDynamicKeyRegistry::class);
        $dynamicKeyRegistry->writeDetectedPatterns($dynamicKeys);
        $scanResults = $dynamicKeyRegistry->mergeEnumeratedScanResults($scanResults);
        app(VoxFrontendManifest::class)->writeFromScanResults($scanResults, $dynamicKeys);

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
        $this->outputDynamicKeys($dynamicKeys);
        $updater = new TranslationFileUpdater($fileRepository, app(VoxDynamicKeyRegistry::class));

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
     * @param  array<int, array{pattern: string, prefix: string, suffix: string, source: string|null, is_frontend: bool, file: string, line: int|null, context: string|null}>  $dynamicKeys
     */
    private function outputDynamicKeys(array $dynamicKeys): void
    {
        if ($dynamicKeys === []) {
            return;
        }

        info('Dynamic translation patterns detected and registered.');
        table(
            ['Pattern', 'Exposure', 'Source', 'Location'],
            array_map(
                fn (array $dynamicKey) => [
                    $this->formatDynamicDisplay($dynamicKey['pattern']),
                    $dynamicKey['is_frontend'] ? 'Frontend' : 'Backend',
                    $this->formatDynamicSource($dynamicKey),
                    $this->formatDynamicLocation($dynamicKey),
                ],
                $dynamicKeys
            )
        );
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
}
