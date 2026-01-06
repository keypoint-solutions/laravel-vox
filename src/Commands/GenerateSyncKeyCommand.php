<?php

namespace KeypointSolutions\LaravelVox\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;
use KeypointSolutions\LaravelVox\Support\VoxSyncKey;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class GenerateSyncKeyCommand extends Command
{
    public $signature = 'vox:generate-sync-key {--force : Overwrite existing sync key}';

    public $description = 'Generate and store a sync key for remote environments.';

    public function handle(): int
    {
        $syncKey = app(VoxSyncKey::class);
        $existingKey = $syncKey->get();

        if ($existingKey !== null && $existingKey !== '' && ! $this->option('force')) {
            warning('A sync key already exists. Use --force to overwrite it.');

            return self::FAILURE;
        }

        $key = Str::random(64);

        if (! $syncKey->store($key)) {
            warning('Failed to save sync key. Make sure the config file is published and writable.');

            return self::FAILURE;
        }

        app(VoxAuditLogger::class)->record('generate-sync-key');

        info('Sync key generated and saved to config:');
        info($key);

        return self::SUCCESS;
    }
}
