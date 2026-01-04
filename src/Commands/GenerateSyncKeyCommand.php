<?php

namespace KeypointSolutions\LaravelVox\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;
use KeypointSolutions\LaravelVox\Support\VoxSyncKey;
use function Laravel\Prompts\info;

class GenerateSyncKeyCommand extends Command
{
    public $signature = 'vox:generate-sync-key';

    public $description = 'Generate and store a sync key for remote environments.';

    public function handle(): int
    {
        $key = Str::random(64);

        app(VoxSyncKey::class)->store($key);
        app(VoxAuditLogger::class)->record('generate-sync-key');

        info('Sync key generated:');
        info($key);

        return self::SUCCESS;
    }
}
