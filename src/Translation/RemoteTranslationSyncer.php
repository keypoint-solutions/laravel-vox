<?php

namespace KeypointSolutions\LaravelVox\Translation;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Models\VoxEnvironment;
use KeypointSolutions\LaravelVox\Support\VoxArchive;
use RuntimeException;

class RemoteTranslationSyncer
{
    public function __construct(
        private VoxArchive $archive,
        private RemoteTranslationSnapshot $snapshot,
        private RemoteReconciliation $reconciliation,
    ) {}

    public function sync(VoxEnvironment $environment): int
    {
        if ($environment->secret_key === '') {
            throw new RuntimeException('The environment is missing a sync key.');
        }

        $environment->refresh();
        $endpoint = $this->endpoint($environment->url);
        $response = Http::accept('application/json')
            ->timeout(30)
            ->withHeaders(['X-Vox-Key' => $environment->secret_key])
            ->post($endpoint);

        if (! $response->successful()) {
            throw new RuntimeException("Remote sync failed with HTTP {$response->status()}.");
        }

        if (strlen($response->body()) > 50 * 1024 * 1024) {
            throw new RuntimeException('Remote snapshot exceeds the allowed size.');
        }

        if (! str_starts_with($response->body(), 'PK')) {
            $payload = $response->json();

            if (! is_array($payload) || ($payload['format'] ?? null) !== RemoteTranslationSnapshot::FORMAT) {
                throw new RuntimeException('The remote endpoint did not return a supported Vox snapshot.');
            }

            return $this->reconciliation->ingest($environment, $this->snapshot->validate($payload['values'] ?? null));
        }

        $directory = storage_path('vox');

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $archivePath = $directory.'/remote-sync-'.Str::uuid().'.zip';
        $remoteLangPath = $directory.'/remote-lang-'.Str::uuid();
        File::put($archivePath, $response->body());

        try {
            $this->archive->extractArchive($archivePath, $remoteLangPath);

            return $this->reconciliation->ingest($environment, $this->snapshot->fromDirectory($remoteLangPath));
        } finally {
            File::delete($archivePath);
            File::deleteDirectory($remoteLangPath);
        }

    }

    private function endpoint(string $url): string
    {
        $endpoint = rtrim($url, '/');

        if (! Str::endsWith($endpoint, '/sync')) {
            $endpoint .= '/vox/sync';
        }

        return $endpoint;
    }
}
