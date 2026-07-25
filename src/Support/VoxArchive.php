<?php

namespace KeypointSolutions\LaravelVox\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Translation\TranslationFileValidator;
use RuntimeException;
use ZipArchive;

class VoxArchive
{
    private const MAX_ENTRIES = 5000;

    private const MAX_UNCOMPRESSED_BYTES = 50 * 1024 * 1024;

    public function __construct(private TranslationFileValidator $validator) {}

    public function createLangArchive(string $sourcePath): string
    {
        $archivePath = storage_path('vox/lang-'.Str::uuid().'.zip');
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
            $extension = strtolower($file->getExtension());

            if (Str::startsWith($file->getFilename(), 'php_') && $file->getExtension() === 'json') {
                continue;
            }

            if (! in_array($extension, ['php', 'json'], true)) {
                continue;
            }

            $this->validator->assertTranslationPath($relativePath);
            $this->validator->validateFile($file->getPathname());
            $zip->addFile($file->getPathname(), $relativePath);
        }

        $zip->close();

        return $archivePath;
    }

    public function extractArchive(string $archivePath, string $destinationPath): int
    {
        $zip = new ZipArchive;

        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('Unable to open archive.');
        }

        $stagingPath = storage_path('vox/archive-'.Str::uuid());

        try {
            $fileCount = $this->assertSafeEntries($zip);
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

            return $fileCount;
        } finally {
            $zip->close();
            File::deleteDirectory($stagingPath);
        }
    }

    private function assertSafeEntries(ZipArchive $zip): int
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw new RuntimeException('Archive contains too many entries.');
        }

        $fileCount = 0;
        $uncompressedBytes = 0;

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

            if (str_ends_with($normalized, '/')) {
                continue;
            }

            $statistics = $zip->statIndex($index);
            $size = is_array($statistics) ? ($statistics['size'] ?? null) : null;

            if (! is_int($size) || $size < 0) {
                throw new RuntimeException('Archive contains an invalid entry size.');
            }

            $uncompressedBytes += $size;

            if ($uncompressedBytes > self::MAX_UNCOMPRESSED_BYTES) {
                throw new RuntimeException('Archive expands beyond the allowed size.');
            }

            $this->validator->assertTranslationPath($normalized);
            $contents = $zip->getFromIndex($index);

            if (! is_string($contents)) {
                throw new RuntimeException("Unable to read archive entry [{$normalized}].");
            }

            $this->validator->validateContents($contents, $normalized);
            $fileCount++;
        }

        if ($fileCount === 0) {
            throw new RuntimeException('Archive does not contain translation files.');
        }

        return $fileCount;
    }
}
