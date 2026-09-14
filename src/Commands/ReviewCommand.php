<?php

namespace KeypointSolutions\LaravelVox\Commands;

use Illuminate\Console\Command;
use KeypointSolutions\LaravelVox\Translation\RemoteReconciliation;

class ReviewCommand extends Command
{
    public $signature = 'vox:review
        {--environment=files : Source environment ID, files, or all}
        {--accept-all : Accept and approve all incoming changes from this source}
        {--keep-all : Keep local wording for all incoming changes from this source}
        {--publish : Publish the accepted selection immediately}';

    public $description = 'Inspect previously imported changes, then optionally accept or keep a batch.';

    public function handle(RemoteReconciliation $reconciliation): int
    {
        if (($this->option('accept-all') && $this->option('keep-all')) || ($this->option('publish') && ! $this->option('accept-all'))) {
            $this->error('Choose accept-all or keep-all. Publishing requires accept-all.');

            return self::FAILURE;
        }
        $source = (string) $this->option('environment');
        if (! in_array($source, ['files', 'all'], true) && (! ctype_digit($source) || (int) $source < 1)) {
            $this->error('Choose files, all, or a configured environment ID.');

            return self::FAILURE;
        }
        $review = $reconciliation->page(['environment_id' => match ($source) {
            'files' => -1,
            'all' => null,
            default => (int) $source,
        }]);
        $this->table(['Group', 'Key', 'Locale', 'Current wording', 'Incoming wording', 'State'], array_map(
            fn (array $row): array => [$row['group'], $row['key'], $row['locale'], $row['local_value'], $row['remote_value'], $row['state']],
            $review['data'],
        ));
        $this->info("{$review['actionable_count']} incoming changes. Showing the first {$review['per_page']} values; use the Sync page for individual decisions.");
        if (! $this->option('accept-all') && ! $this->option('keep-all')) {
            return self::SUCCESS;
        }
        if ($review['actionable_count'] === 0) {
            return self::SUCCESS;
        }
        $count = $reconciliation->resolve([
            'action' => $this->option('accept-all') ? 'accept' : 'keep',
            'publish' => (bool) $this->option('publish'),
            'all_matching' => true,
            'filters' => $review['filters'],
            'selection_token' => $review['selection_token'],
        ]);
        $this->info("Processed {$count} incoming values.");

        return self::SUCCESS;
    }
}
