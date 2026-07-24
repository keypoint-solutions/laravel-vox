<?php

namespace KeypointSolutions\LaravelVox\Translation;

use Illuminate\Support\Facades\File;

class RemoteTranslationMerger
{
    public function __construct(private TranslationFileRepository $files) {}

    public function merge(string $remoteLangPath): void
    {
        foreach (File::allFiles($remoteLangPath) as $file) {
            $relativePath = str_replace('\\', '/', $file->getRelativePathname());

            if ($file->getExtension() === 'php') {
                $this->mergeGroupFile($file->getPathname(), $relativePath);

                continue;
            }

            if ($file->getExtension() === 'json' && ! str_starts_with($file->getFilename(), 'php_')) {
                $this->mergeJsonFile($file->getPathname(), $relativePath);
            }
        }
    }

    private function mergeGroupFile(string $remotePath, string $relativePath): void
    {
        $parts = explode('/', $relativePath);
        $group = pathinfo(array_pop($parts) ?? '', PATHINFO_FILENAME);
        $locale = array_pop($parts);
        $namespace = null;

        if (($parts[0] ?? null) === 'vendor') {
            $namespace = $parts[1] ?? null;
        }

        if (! is_string($locale) || $locale === '' || $group === '') {
            return;
        }

        $remote = require $remotePath;

        if (! is_array($remote)) {
            return;
        }

        $resolvedGroup = is_string($namespace) && $namespace !== ''
            ? $namespace.'::'.$group
            : $group;
        $local = $this->files->loadGroup($locale, $resolvedGroup);
        $merged = array_replace_recursive($local, $remote);

        $this->files->saveGroup(
            $locale,
            $resolvedGroup,
            $merged,
            [],
            $this->files->loadLineComments($locale, $resolvedGroup),
            array_values($this->files->loadObsoleteComments($locale, $resolvedGroup))
        );
    }

    private function mergeJsonFile(string $remotePath, string $relativePath): void
    {
        $parts = explode('/', $relativePath);
        $filename = array_pop($parts);

        if (! is_string($filename)) {
            return;
        }

        $locale = pathinfo($filename, PATHINFO_FILENAME);
        $namespace = ($parts[0] ?? null) === 'vendor' ? ($parts[1] ?? null) : null;
        $remote = json_decode(File::get($remotePath), true);

        if (! is_array($remote) || $locale === '') {
            return;
        }

        $resolvedNamespace = is_string($namespace) && $namespace !== '' ? $namespace : null;
        $local = $this->files->loadJson($locale, $resolvedNamespace);

        $this->files->saveJson($locale, array_replace($local, $remote), $resolvedNamespace);
    }
}
