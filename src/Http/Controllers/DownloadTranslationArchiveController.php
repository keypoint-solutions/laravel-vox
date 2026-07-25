<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Support\VoxArchive;
use KeypointSolutions\LaravelVox\Translation\TranslationFileRepository;
use KeypointSolutions\LaravelVox\Translation\TranslationPublisher;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DownloadTranslationArchiveController
{
    public function __invoke(
        TranslationPublisher $publisher,
        TranslationFileRepository $files,
        VoxArchive $archive,
    ): BinaryFileResponse {
        $stagingPath = storage_path('vox/download-'.Str::uuid());
        File::makeDirectory($stagingPath, 0755, true);

        try {
            if (File::isDirectory($files->langPath()) && ! File::copyDirectory($files->langPath(), $stagingPath)) {
                throw new RuntimeException('Unable to prepare the translation archive.');
            }

            $publisher->publishTo($stagingPath);
            $archivePath = $archive->createLangArchive($stagingPath);
        } finally {
            File::deleteDirectory($stagingPath);
        }

        $filename = 'laravel-vox-translations-'.now()->format('Y-m-d-His').'.zip';

        return response()->download($archivePath, $filename)->deleteFileAfterSend(true);
    }
}
