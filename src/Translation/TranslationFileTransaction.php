<?php

namespace KeypointSolutions\LaravelVox\Translation;

use Closure;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class TranslationFileTransaction
{
    /** @var array<string, string|null> */
    private array $originals = [];

    private bool $active = false;

    public function run(Closure $callback): mixed
    {
        if ($this->active) {
            return $callback();
        }
        $this->active = true;
        try {
            return $callback();
        } catch (Throwable $exception) {
            foreach (array_reverse($this->originals, true) as $path => $contents) {
                if ($contents === null) {
                    File::delete($path);
                } else {
                    File::replace($path, $contents);
                }
            }
            throw $exception;
        } finally {
            $this->active = false;
            $this->originals = [];
        }
    }

    public function replace(string $path, string $contents): void
    {
        $this->remember($path);
        File::ensureDirectoryExists(dirname($path));
        File::replace($path, $contents);
        if (! File::exists($path) || File::get($path) !== $contents) {
            throw new RuntimeException('Unable to write translation file: '.$path);
        }
    }

    public function delete(string $path): void
    {
        $this->remember($path);
        if (File::exists($path) && ! File::delete($path)) {
            throw new RuntimeException('Unable to remove translation file: '.$path);
        }
    }

    private function remember(string $path): void
    {
        if ($this->active && ! array_key_exists($path, $this->originals)) {
            $this->originals[$path] = File::exists($path) ? File::get($path) : null;
        }
    }
}
