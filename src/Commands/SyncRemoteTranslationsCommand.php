<?php

namespace KeypointSolutions\LaravelVox\Commands;

use Illuminate\Console\Command;
use KeypointSolutions\LaravelVox\Models\VoxEnvironment;
use KeypointSolutions\LaravelVox\Translation\RemoteReconciliation;
use KeypointSolutions\LaravelVox\Translation\RemoteTranslationSyncer;
use Throwable;

use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\warning;

class SyncRemoteTranslationsCommand extends Command
{
    public $signature = 'vox:sync-remote
        {--include-drafts : Fetch editable remote values instead of published wording}
        {--environment= : Environment ID for non-interactive pulls}
        {--check : Fail if changes need review or accepted values still need publishing}';

    public $description = 'Pull remote translation candidates for review without changing local files.';

    public function handle(): int
    {
        $environments = VoxEnvironment::query()->orderBy('name')->get();

        if ($environments->isEmpty()) {
            warning('No environments configured.');

            return self::FAILURE;
        }

        $selected = $this->option('environment') ?? config('vox.sync.default_environment');

        if ($selected === null && ! $this->input->isInteractive()) {
            warning('Pass --environment=ID for a non-interactive remote pull.');

            return self::FAILURE;
        }

        if ($selected === null) {
            $selected = select(
                'Which environment should be synced?',
                $environments->pluck('name', 'id')->all()
            );
        }

        $environment = $environments->firstWhere('id', (int) $selected);

        if ($environment === null) {
            warning('Environment not found.');

            return self::FAILURE;
        }

        try {
            $count = app(RemoteTranslationSyncer::class)->sync($environment, (bool) $this->option('include-drafts'));
        } catch (Throwable $exception) {
            warning($exception->getMessage());

            return self::FAILURE;
        }

        info("Pulled {$count} remote values for review. Local translations and files were not changed.");

        if ($this->option('check')) {
            try {
                $reconciliation = app(RemoteReconciliation::class);
                $unresolved = $reconciliation->unresolvedCount($environment->id);
                $unpublished = $reconciliation->unpublishedCount($environment->id);
            } catch (Throwable $exception) {
                warning($exception->getMessage());

                return self::FAILURE;
            }

            if ($unresolved > 0) {
                warning("{$unresolved} remote changes need review. Resolve them before deploying.");

                return self::FAILURE;
            }

            if ($unpublished > 0) {
                warning("{$unpublished} local values still need approval and publishing before deploying.");

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
