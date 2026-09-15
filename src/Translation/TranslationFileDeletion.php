<?php

namespace KeypointSolutions\LaravelVox\Translation;

use Illuminate\Support\Facades\File;
use KeypointSolutions\LaravelVox\Support\VoxDynamicKeyRegistry;

class TranslationFileDeletion
{
    public function __construct(
        private TranslationFileRepository $files,
        private TranslationFileWriter $writer,
        private TranslationFileValidator $validator,
    ) {}

    /** @return array<string, array{before: string, after: string|null}> */
    public function prepare(iterable $rows, bool $includeRuntime = true): array
    {
        $locales = [];
        foreach (File::isDirectory($this->files->langPath()) ? File::allFiles($this->files->langPath()) : [] as $file) {
            $parts = explode(DIRECTORY_SEPARATOR, $file->getRelativePathname());
            $locales[] = pathinfo($parts[0] === 'vendor' ? ($parts[2] ?? '') : $parts[0], PATHINFO_FILENAME);
        }
        $changes = [];
        $data = [];
        foreach ($rows as $row) {
            foreach (array_unique($locales) as $locale) {
                $key = $row->key;
                $json = $row->group === null || $row->group === 'json';
                $namespace = null;
                if ($json && str_contains($key, '::')) {
                    [$namespace, $key] = explode('::', $key, 2);
                }
                $path = $json ? $this->files->jsonPath($locale, $namespace) : $this->files->groupPath($locale, $row->group);
                if (! File::isFile($path)) {
                    continue;
                }
                $data[$path] ??= $json ? $this->files->loadJson($locale, $namespace) : $this->files->loadGroup($locale, $row->group);
                $updated = $data[$path];
                if ($json) {
                    unset($updated[$key]);
                } else {
                    app(TranslationGroupFormat::class)->remove($updated, $key);
                }
                if ($updated === $data[$path]) {
                    continue;
                }
                $data[$path] = $updated;
                $comments = $json ? [] : $this->files->loadLineComments($locale, $row->group);
                unset($comments[$key]);
                $obsolete = $json ? [] : $this->files->loadObsoleteComments($locale, $row->group);
                unset($obsolete[$key]);
                $contents = $json ? $this->writer->toJson($updated) : $this->writer->toPhp($updated, [], $comments, array_values($obsolete));
                $this->validator->validateContents($contents, $path);
                $changes[$path] = ['before' => $changes[$path]['before'] ?? File::get($path), 'after' => $updated === [] ? null : $contents];
            }
        }

        $runtimePath = config('vox.frontend.runtime.path', storage_path('vox/frontend-translations'));
        if ($includeRuntime && File::isDirectory($runtimePath)) {
            foreach (File::files($runtimePath) as $file) {
                if ($file->getExtension() !== 'json') {
                    continue;
                }
                $path = $file->getPathname();
                $before = File::get($path);
                $values = json_decode($before, true, 512, JSON_THROW_ON_ERROR);
                $updated = $values;
                foreach ($rows as $row) {
                    unset($updated[app(VoxDynamicKeyRegistry::class)->fullKey($row->key, $row->group)]);
                }
                if ($updated !== $values) {
                    $contents = $this->writer->toJson($updated);
                    $this->validator->validateContents($contents, $path);
                    $changes[$path] = ['before' => $before, 'after' => $updated === [] ? null : $contents];
                }
            }
        }

        return $changes;
    }
}
