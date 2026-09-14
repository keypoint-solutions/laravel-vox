<?php

namespace KeypointSolutions\LaravelVox\Support;

use Closure;
use Illuminate\Support\Facades\File;
use RuntimeException;

class VoxMutationLock
{
    private int $depth = 0;

    public function run(Closure $callback): mixed
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

            $this->depth++;

            return $callback();
        } finally {
            $this->depth = 0;
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
