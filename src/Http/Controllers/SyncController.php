<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use KeypointSolutions\LaravelVox\Support\VoxConfig;
use KeypointSolutions\LaravelVox\Support\VoxSettingsRepository;
use KeypointSolutions\LaravelVox\Translation\Remote\RemoteTranslationSnapshot;
use KeypointSolutions\LaravelVox\Translation\Remote\VoxArchive;
use KeypointSolutions\LaravelVox\Translation\Remote\VoxSyncKey;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SyncController
{
    public function __invoke(
        Request $request,
        VoxArchive $archive,
        VoxSyncKey $syncKey,
        VoxSettingsRepository $settings,
        RemoteTranslationSnapshot $snapshot,
    ): BinaryFileResponse|JsonResponse {
        if (! $settings->get('sync_enabled', config('vox.sync.enabled', true))) {
            abort(404);
        }

        $configuredKey = $syncKey->get();
        $providedKey = $request->header('X-Vox-Key') ?? $request->input('key');

        if (! is_string($configuredKey) || $configuredKey === '' || ! is_string($providedKey) || ! hash_equals($configuredKey, $providedKey)) {
            abort(403);
        }

        if ($request->wantsJson()) {
            return response()->json($snapshot->export($request->boolean('include_drafts')))->header('Cache-Control', 'no-store');
        }

        $langPath = VoxConfig::langPath();
        $archivePath = $archive->createLangArchive($langPath);

        return response()->download($archivePath)->deleteFileAfterSend(true);
    }
}
