<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use KeypointSolutions\LaravelVox\Support\VoxArchive;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;
use RuntimeException;

class ImportTranslationArchiveController
{
    public function __invoke(
        Request $request,
        VoxArchive $archive,
        VoxAuditLogger $auditLogger,
    ): RedirectResponse {
        $uploadedFile = $request->validate([
            'archive' => ['required', 'file', 'max:10240'],
        ])['archive'];

        if (! $uploadedFile instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'archive' => 'Choose a ZIP archive to import.',
            ]);
        }

        if (strtolower($uploadedFile->getClientOriginalExtension()) !== 'zip') {
            throw ValidationException::withMessages([
                'archive' => 'The translation archive must use the .zip extension.',
            ]);
        }

        $path = $uploadedFile->getRealPath();

        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages([
                'archive' => 'The uploaded translation archive could not be read.',
            ]);
        }

        try {
            $fileCount = $archive->extractArchive(
                $path,
                (string) config('vox.paths.lang', lang_path())
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'archive' => $exception->getMessage(),
            ]);
        }

        $auditLogger->record('translation-archive-imported', [
            'files' => $fileCount,
            'filename' => $uploadedFile->getClientOriginalName(),
        ]);

        $noun = $fileCount === 1 ? 'file' : 'files';

        return Inertia::flash(
            'success',
            "Imported {$fileCount} translation {$noun}. Run Local sync when you want to update the Vox database."
        )->back();
    }
}
