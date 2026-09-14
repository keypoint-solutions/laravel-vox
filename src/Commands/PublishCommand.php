<?php

namespace KeypointSolutions\LaravelVox\Commands;

use Illuminate\Console\Command;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;
use KeypointSolutions\LaravelVox\Translation\TranslationPublisher;
use Throwable;

class PublishCommand extends Command
{
    public $signature = 'vox:publish {--published-only : Rewrite recorded defaults and published overrides without publishing pending edits or deletions}';

    public $description = 'Publish approved translation edits, or rewrite only previously published wording.';

    public function handle(TranslationPublisher $publisher, VoxAuditLogger $auditLogger): int
    {
        try {
            if ($this->option('published-only')) {
                $result = $publisher->regenerate();
                $this->info('Published translation files rewritten. Pending edits and deletions were preserved.');
            } else {
                $result = $publisher->publish();
                $this->info('Approved translation edits and pending deletions published.');
            }

            $auditLogger->record('publish', [
                'published_only' => (bool) $this->option('published-only'),
                'values' => $result->values(),
                'files' => $result->fileCount(),
                'frontend_files' => $result->frontendFileCount(),
                'deleted_keys' => $result->deletedKeys(),
            ]);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
