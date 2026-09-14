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

    /** @var array<int, Closure> */
    private array $afterCommit = [];

    public function run(Closure $callback): mixed
    {
        if ($this->active) {
            return $callback();
        }
        $this->active = true;
        try {
            $result = $callback();
            $afterCommit = $this->afterCommit;
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
            $this->afterCommit = [];
        }

        foreach ($afterCommit as $callback) {
            $callback();
        }

        return $result;
    }

    public function afterCommit(Closure $callback): void
    {
        if ($this->active) {
            $this->afterCommit[] = $callback;
        } else {
            $callback();
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
