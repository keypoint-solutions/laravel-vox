<?php

namespace KeypointSolutions\LaravelVox\Commands;

use Illuminate\Console\Command;
use KeypointSolutions\LaravelVox\Support\VoxConfig;
use KeypointSolutions\LaravelVox\Translation\TranslationCheckpoints;
use RuntimeException;

class CheckpointCommand extends Command
{
    public $signature = 'vox:checkpoint {label? : Name for a new checkpoint} {--restore= : Restore a checkpoint ID} {--force : Restore without interactive confirmation}';

    public $description = 'Create, list, or restore translation checkpoints.';

    public function handle(TranslationCheckpoints $checkpoints): int
    {
        if (! $checkpoints->available()) {
            $this->error('Run vox:setup to install checkpoint storage.');

            return self::FAILURE;
        }
        if ($this->option('restore') !== null) {
            $id = filter_var($this->option('restore'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                $this->error('Provide a valid checkpoint ID.');

                return self::FAILURE;
            }
            if (! $this->option('force') && ! $this->confirm('Restore translation data and files to this checkpoint? All later recorded changes will be undone.')) {
                return self::FAILURE;
            }
            try {
                $checkpoints->restore($id);
            } catch (RuntimeException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
            $this->info('Checkpoint restored. The rollback can be undone using the newest checkpoint.');
        } elseif ($this->argument('label') !== null) {
            $id = $checkpoints->create(mb_substr((string) $this->argument('label'), 0, 200));
            $this->info('Created checkpoint #'.$id.'.');
        } else {
            $this->table(['ID', 'Checkpoint', 'Created'], VoxConfig::connection()
                ->table('vox_checkpoints')->latest('id')->limit(25)->get(['id', 'label', 'created_at'])->map(fn ($row): array => (array) $row)->all());
        }

        return self::SUCCESS;
    }
}
