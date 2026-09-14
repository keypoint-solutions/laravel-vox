<?php

namespace KeypointSolutions\LaravelVox\Translation;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Support\VoxDynamicKeyRegistry;

class TranslationDeletionEligibility
{
    private array $catalogues = [];

    private ?array $scan = null;

    private ?array $locales = null;

    public function __construct(private TranslationFileRepository $files, private VoxDynamicKeyRegistry $registry) {}

    public function reason(VoxTranslation $row, ?array $scan = null): ?string
    {
        if ($row->is_pending_delete) {
            return 'Pending deletion. Publish to remove this key from language files, or restore it to cancel.';
        }
        if ($row->is_ignored) {
            return 'Restore this ignored key before deleting it.';
        }
        if ($row->source !== 'dynamic' && $this->isPublished($row)) {
            return 'This key exists in published language files. Use Ignore in Vox to stop managing it.';
        }
        if (! $row->is_orphan && $row->source !== 'dynamic') {
            return 'This key has direct source usage. Only orphans and dynamic keys can be deleted.';
        }
        if ($scan === null) {
            $this->scan ??= (new TranslationScanner(base_path(), config('vox.parse.paths', []), config('vox.parse.exclude', []), config('vox.parse.extensions', []), 0))->scan();
            $scan = $this->scan;
        }
        if (isset($scan[$this->registry->fullKey($row->key, $row->group)])) {
            return 'This key is referenced directly in source code. Use Ignore in Vox to stop managing it.';
        }
        $dynamic = $this->registry->match($row->key, $row->group);
        if ($row->is_orphan && $dynamic !== null) {
            return 'This key now matches a retention rule or dynamic usage. Run Sync to refresh its status.';
        }
        if (! $row->is_orphan && $dynamic === null) {
            return 'The dynamic usage for this key has changed. Run Sync to refresh its status.';
        }

        return null;
    }

    private function isPublished(VoxTranslation $row): bool
    {
        if (! File::isDirectory($this->files->langPath())) {
            return false;
        }
        if ($this->locales === null) {
            $this->locales = [];
            foreach (File::allFiles($this->files->langPath()) as $file) {
                $parts = explode(DIRECTORY_SEPARATOR, $file->getRelativePathname());
                $this->locales[] = $parts[0] === 'vendor' ? ($parts[2] ?? '') : pathinfo($parts[0], PATHINFO_FILENAME);
            }
            $this->locales = array_unique($this->locales);
        }
        foreach ($this->locales as $locale) {
            $key = $row->key;
            if ($row->group === 'json' || $row->group === null) {
                $namespace = null;
                if (str_contains($key, '::')) {
                    [$namespace, $key] = explode('::', $key, 2);
                }
                $cacheKey = 'json:'.$namespace.':'.$locale;
                $values = $this->catalogues[$cacheKey] ??= $this->files->loadJson($locale, $namespace);
            } else {
                $cacheKey = 'group:'.$row->group.':'.$locale;
                $values = $this->catalogues[$cacheKey] ??= Arr::dot($this->files->loadGroup($locale, $row->group));
            }
            if (array_key_exists($key, $values)) {
                return true;
            }
        }

        return false;
    }
}
