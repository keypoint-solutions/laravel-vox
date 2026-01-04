<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Http\Request;
use KeypointSolutions\LaravelVox\Support\VoxArchive;
use KeypointSolutions\LaravelVox\Support\VoxSyncKey;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SyncController
{
    public function __invoke(Request $request, VoxArchive $archive, VoxSyncKey $syncKey): BinaryFileResponse
    {
        $configuredKey = $syncKey->get();
        $providedKey = $request->header('X-Vox-Key') ?? $request->input('key');

        if (! is_string($configuredKey) || $configuredKey === '' || $configuredKey !== $providedKey) {
            abort(403);
        }

        $langPath = config('vox.paths.lang', resource_path('lang'));
        $archivePath = $archive->createLangArchive($langPath);

        return response()->download($archivePath)->deleteFileAfterSend(true);
    }
}
