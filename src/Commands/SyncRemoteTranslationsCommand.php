<?php

namespace KeypointSolutions\LaravelVox\Commands;

use Illuminate\Console\Command;
use KeypointSolutions\LaravelVox\Models\VoxEnvironment;
use KeypointSolutions\LaravelVox\Translation\RemoteTranslationSyncer;
use Throwable;

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

        try {
            app(RemoteTranslationSyncer::class)->sync($environment);
        } catch (Throwable $exception) {
            warning($exception->getMessage());

            return self::FAILURE;
        }

        info('Remote translations synced.');

        return self::SUCCESS;
    }
}
