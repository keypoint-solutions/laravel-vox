<?php

namespace KeypointSolutions\LaravelVox\Commands;

use Illuminate\Console\Command;
use KeypointSolutions\LaravelVox\Models\VoxAudit;

use function Laravel\Prompts\info;

class CleanupCommand extends Command
{
    public $signature = 'vox:cleanup';

    public $description = 'Clean up old Vox audit records.';

    public function handle(): int
    {
        $deleted = VoxAudit::query()
            ->where('created_at', '<', now()->subDays(90))
            ->delete();

        info("Deleted {$deleted} old audit records.");

        return self::SUCCESS;
    }
}
