<?php

namespace KeypointSolutions\LaravelVox\Support;

use Illuminate\Support\Facades\File;

use function Laravel\Prompts\info;
use function Laravel\Prompts\table;

class TranslationFileChangeReporter
{
    /** @return array<string, string> */
    public function snapshot(string $langPath): array
    {
        $snapshot = [];
        foreach (File::isDirectory($langPath) ? File::allFiles($langPath) : [] as $file) {
            if (in_array(strtolower($file->getExtension()), ['php', 'json'], true)) {
                $snapshot[$file->getPathname()] = hash('sha256', File::get($file->getPathname()));
            }
        }

        return $snapshot;
    }

    /**
     * @param  array<string, string>  $before
     * @param  array<string, string>  $after
     */
    public function report(string $langPath, array $before, array $after): void
    {
        $changes = [];
        $prefix = rtrim($langPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $paths = array_unique([...array_keys($before), ...array_keys($after)]);
        sort($paths);
        foreach ($paths as $path) {
            if (($before[$path] ?? null) === ($after[$path] ?? null)) {
                continue;
            }
            $changes[] = [
                str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path,
                ! isset($after[$path]) ? 'Deleted' : (! isset($before[$path]) ? 'Added' : 'Modified'),
            ];
        }
        if ($changes === []) {
            info('No translation files modified.');

            return;
        }
        table(['Modified files', 'Change'], $changes);
    }
}
