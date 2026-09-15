<?php

namespace KeypointSolutions\LaravelVox\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Support\TranslationFileChangeReporter;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;
use KeypointSolutions\LaravelVox\Support\VoxDynamicKeyRegistry;
use KeypointSolutions\LaravelVox\Support\VoxFrontendManifest;
use KeypointSolutions\LaravelVox\Translation\TranslationFileRepository;
use KeypointSolutions\LaravelVox\Translation\TranslationFileUpdater;
use KeypointSolutions\LaravelVox\Translation\TranslationFileWriter;
use KeypointSolutions\LaravelVox\Translation\TranslationScanPreparation;

use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class ParseTranslationsCommand extends Command
{
    public $signature = 'vox:parse';

    public $description = 'Parse codebase and update language files.';

    public function handle(TranslationScanPreparation $preparation): int
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

        $scan = spin(fn () => $preparation->prepare(), 'Scanning translation keys');
        $scanResults = $scan['results'];
        $dynamicKeys = $scan['dynamic_keys'];
        $locales = $scan['locales'];
        $baseLocale = $scan['base_locale'];
        app(VoxFrontendManifest::class)->writeFromScanResults($scanResults, $dynamicKeys);

        if ($this->output->isVerbose()) {
            $this->outputAnalyzedFiles($scan['files']);
        }

        $fileRepository = new TranslationFileRepository(new TranslationFileWriter);
        $beforeSnapshot = app(TranslationFileChangeReporter::class)->snapshot($fileRepository->langPath());
        $this->outputDynamicKeys($dynamicKeys);
        $updater = new TranslationFileUpdater($fileRepository, app(VoxDynamicKeyRegistry::class));

        $result = $updater->updateFromScan($scanResults, $locales, $baseLocale);

        app(VoxAuditLogger::class)->record('parse', [
            'added' => $result->added(),
            'removed' => $result->removed(),
        ]);

        app(TranslationFileChangeReporter::class)->report(
            $fileRepository->langPath(),
            $beforeSnapshot,
            app(TranslationFileChangeReporter::class)->snapshot($fileRepository->langPath())
        );

        info('Translation files updated.');
        table(['Metric', 'Count'], [
            ['Added keys', (string) $result->added()],
            ['Removed keys', (string) $result->removed()],
        ]);

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
        if (! $this->output->isVerbose()) {
            $patterns = collect($dynamicKeys)->groupBy('pattern')->sortKeys();
            table(['Pattern', 'Used in', 'Occurrences'], $patterns->map(function ($occurrences, string $pattern): array {
                $exposure = $occurrences->pluck('is_frontend')->unique();

                return [
                    $this->formatDynamicDisplay($pattern),
                    $exposure->count() > 1 ? 'Frontend + backend' : ($exposure->first() ? 'Frontend' : 'Backend'),
                    (string) $occurrences->count(),
                ];
            })->values()->all());
            $this->line('Use -v to show source context and locations.');

            return;
        }

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

    /** @param array<int, string> $files */
    private function outputAnalyzedFiles(array $files): void
    {
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
