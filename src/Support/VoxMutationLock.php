<?php

namespace KeypointSolutions\LaravelVox\Support;

use Closure;
use Illuminate\Support\Facades\File;
use KeypointSolutions\LaravelVox\Translation\TranslationCheckpoints;
use RuntimeException;

class VoxMutationLock
{
    private int $depth = 0;

    public function run(Closure $callback, string $label = 'translation changes'): mixed
    {
        if ($this->depth > 0) {
            return $callback();
        }

        $path = (string) config('vox.deployment.lock_path', storage_path('vox/publish.lock'));
        File::ensureDirectoryExists(dirname($path));
        $handle = fopen($path, 'c');

        if ($handle === false) {
            throw new RuntimeException('Unable to open the Vox publishing lock.');
        }

        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new VoxMutationConflict('Vox is publishing or deploying translations. Please retry shortly.');
            }

            $this->ensureMemoryLimit();
            $this->depth++;

            return app(TranslationCheckpoints::class)->run($label, $callback);
        } finally {
            $this->depth = 0;
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function ensureMemoryLimit(): void
    {
        $configured = config('vox.memory_limit', '256M');
        if ($configured === null || $configured === false || $configured === '') {
            return;
        }

        if ((! is_string($configured) && ! is_int($configured))
            || preg_match('/^(?:-1|[1-9][0-9]*[KMG]?)$/iD', (string) $configured) !== 1) {
            throw new RuntimeException('Invalid vox.memory_limit. Use a value such as 256M, -1 for unlimited, or null to keep the PHP limit.');
        }

        $limit = (string) $configured;
        $requested = ini_parse_quantity($limit);
        if ($requested !== -1 && $requested <= 0) {
            throw new RuntimeException('Invalid vox.memory_limit: the configured size is out of range.');
        }
        $current = ini_parse_quantity((string) ini_get('memory_limit'));
        if ($current === -1 || ($requested !== -1 && $current >= $requested)) {
            return;
        }

        if (! function_exists('ini_set') || @ini_set('memory_limit', $limit) === false
            || ini_parse_quantity((string) ini_get('memory_limit')) !== $requested) {
            throw new RuntimeException("Unable to raise PHP memory_limit to {$limit}. Adjust your PHP configuration or vox.memory_limit.");
        }
    }
}
