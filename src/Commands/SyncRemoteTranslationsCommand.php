<?php

namespace KeypointSolutions\LaravelVox\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Models\VoxEnvironment;
use KeypointSolutions\LaravelVox\Support\VoxArchive;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;

use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\warning;

class SyncRemoteTranslationsCommand extends Command
{
    public $signature = 'vox:sync-remote';

    public $description = 'Pull translations from a remote environment and sync the database.';

    public function handle(): int
    {
        $environments = VoxEnvironment::query()->orderBy('name')->get();

        if ($environments->isEmpty()) {
            warning('No environments configured.');

            return self::FAILURE;
        }

        $selected = select(
            'Which environment should be synced?',
            $environments->pluck('name', 'id')->all()
        );

        $environment = $environments->firstWhere('id', (int) $selected);

        if ($environment === null) {
            warning('Environment not found.');

            return self::FAILURE;
        }

        if ($environment->secret_key === '') {
            warning('Environment is missing a secret key.');

            return self::FAILURE;
        }

        $endpoint = rtrim($environment->url, '/');

        if (! Str::endsWith($endpoint, '/sync')) {
            $endpoint .= '/vox/sync';
        }

        $response = Http::withHeaders(['X-Vox-Key' => $environment->secret_key])
            ->post($endpoint);

        if (! $response->successful()) {
            warning('Remote sync failed.');

            return self::FAILURE;
        }

        $archivePath = storage_path('vox/remote-sync-'.date('YmdHis').'.zip');
        $directory = dirname($archivePath);

        if (! File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        File::put($archivePath, $response->body());

        $archive = new VoxArchive;
        $archive->extractArchive($archivePath, config('vox.paths.lang', resource_path('lang')));
        File::delete($archivePath);

        $this->call('vox:sync');

        app(VoxAuditLogger::class)->record('sync-remote', [
            'environment_id' => $environment->id,
        ]);

        info('Remote translations synced.');

        return self::SUCCESS;
    }
}
