<?php

namespace KeypointSolutions\LaravelVox\Commands;

use Illuminate\Console\Command;
use KeypointSolutions\LaravelVox\Support\VoxFrontendManifest;
use KeypointSolutions\LaravelVox\Translation\TranslationScanner;

class DiscoverFrontendCommand extends Command
{
    public $signature = 'vox:frontend-discover';

    public $description = 'Refresh the frontend group manifest from source without changing translations or database values.';

    public function handle(VoxFrontendManifest $manifest): int
    {
        $paths = config('vox.parse.paths', []);
        $extensions = config('vox.parse.extensions', []);

        if ($manifest->usesConfiguredGroups()) {
            $manifest->write([]);
            $paths = [];
        } else {
            $scanner = new TranslationScanner(
                base_path(),
                $paths,
                config('vox.parse.exclude', []),
                $extensions,
                (int) config('vox.parse.context_lines', 3),
            );
            $results = $scanner->scan();
            $manifest->writeFromScanResults($results, $scanner->dynamicKeys());
            $paths = $scanner->paths();
        }

        $this->line(json_encode([
            'paths' => $paths,
            'extensions' => $extensions,
            'manifest' => $manifest->path(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
