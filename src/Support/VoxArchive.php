<?php

namespace KeypointSolutions\LaravelVox\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class VoxArchive
{
    public function createLangArchive(string $sourcePath): string
    {
        $archivePath = storage_path('vox/lang-'.date('YmdHis').'.zip');
        $directory = dirname($archivePath);

        if (! File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $zip = new ZipArchive;

        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create archive.');
        }

        $files = File::allFiles($sourcePath);

        foreach ($files as $file) {
            $relativePath = ltrim(str_replace($sourcePath, '', $file->getPathname()), DIRECTORY_SEPARATOR);

            if (Str::startsWith($file->getFilename(), 'php_') && $file->getExtension() === 'json') {
                continue;
            }

            $zip->addFile($file->getPathname(), $relativePath);
        }

        $zip->close();

        return $archivePath;
    }

    public function extractArchive(string $archivePath, string $destinationPath): void
    {
        $zip = new ZipArchive;

        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('Unable to open archive.');
        }

        $stagingPath = storage_path('vox/archive-'.Str::uuid());

        try {
            $this->assertSafeEntries($zip);
            File::makeDirectory($stagingPath, 0755, true);

            if (! $zip->extractTo($stagingPath)) {
                throw new RuntimeException('Unable to extract archive.');
            }

            if (! File::isDirectory($destinationPath)) {
                File::makeDirectory($destinationPath, 0755, true);
            }

            if (! File::copyDirectory($stagingPath, $destinationPath)) {
                throw new RuntimeException('Unable to copy extracted translations.');
            }
        } finally {
            $zip->close();
            File::deleteDirectory($stagingPath);
        }
    }

    private function assertSafeEntries(ZipArchive $zip): void
    {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);

            if (! is_string($name)) {
                throw new RuntimeException('Archive contains an invalid entry.');
            }

            $normalized = str_replace('\\', '/', $name);
            $segments = explode('/', $normalized);
            $isAbsolute = str_starts_with($normalized, '/')
                || preg_match('/^[A-Za-z]:\//', $normalized) === 1;

            if ($isAbsolute || in_array('..', $segments, true)) {
                throw new RuntimeException('Archive contains an unsafe path.');
            }

            $operatingSystem = 0;
            $attributes = 0;

            if (
                $zip->getExternalAttributesIndex($index, $operatingSystem, $attributes)
                && (($attributes >> 16) & 0xF000) === 0xA000
            ) {
                throw new RuntimeException('Archive contains an unsafe symbolic link.');
            }
        }
    }
}
