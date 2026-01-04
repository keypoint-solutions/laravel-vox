<?php

namespace KeypointSolutions\LaravelVox\Support;

use Illuminate\Support\Facades\File;
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

        $zip = new ZipArchive();

        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create archive.');
        }

        $files = File::allFiles($sourcePath);

        foreach ($files as $file) {
            $relativePath = ltrim(str_replace($sourcePath, '', $file->getPathname()), DIRECTORY_SEPARATOR);
            $zip->addFile($file->getPathname(), $relativePath);
        }

        $zip->close();

        return $archivePath;
    }

    public function extractArchive(string $archivePath, string $destinationPath): void
    {
        $zip = new ZipArchive();

        if ($zip->open($archivePath) !== true) {
            throw new \RuntimeException('Unable to open archive.');
        }

        if (! File::exists($destinationPath)) {
            File::makeDirectory($destinationPath, 0755, true);
        }

        $zip->extractTo($destinationPath);
        $zip->close();
    }
}
