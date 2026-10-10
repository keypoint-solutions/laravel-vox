<?php

namespace KeypointSolutions\LaravelVox\Commands\Concerns;

use KeypointSolutions\LaravelVox\Support\VoxMutationLock;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

trait RecordsTranslationCheckpoint
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return app(VoxMutationLock::class)->run(fn (): int => parent::execute($input, $output), $this->getName() ?? 'translation command');
    }
}
