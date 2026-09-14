<?php

namespace KeypointSolutions\LaravelVox\Commands;

use Illuminate\Console\Command;
use KeypointSolutions\LaravelVox\Translation\TranslationDeployment;
use Throwable;

class DeployCommand extends Command
{
    public $signature = 'vox:deploy';

    public $description = 'Import freshly installed translation files and generate live files preserving published overrides and drafts.';

    public function handle(TranslationDeployment $deployment): int
    {
        if ($this->call('vox:setup', ['--force' => true, '--no-interaction' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }
        try {
            $result = $deployment->deploy();
            $this->info("Prepared {$result->translations()} translations. Published manager wording and drafts were preserved.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
