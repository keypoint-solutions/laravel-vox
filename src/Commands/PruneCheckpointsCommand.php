<?php

namespace KeypointSolutions\LaravelVox\Commands;

use Illuminate\Console\Command;
use KeypointSolutions\LaravelVox\Translation\TranslationCheckpoints;

class PruneCheckpointsCommand extends Command
{
    public $signature = 'vox:checkpoint-prune';

    public $description = 'Remove translation checkpoints exceeding the configured retention limit.';

    public function handle(TranslationCheckpoints $checkpoints): int
    {
        $this->info('Removed '.$checkpoints->prune().' old checkpoints.');

        return self::SUCCESS;
    }
}
