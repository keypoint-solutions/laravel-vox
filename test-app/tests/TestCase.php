<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function migrateDatabases(): void
    {
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        $this->artisan('migrate:fresh', [
            '--database' => 'vox',
            '--path' => realpath(__DIR__.'/../../database/migrations'),
            '--realpath' => true,
            '--force' => true,
        ]);
    }

    /**
     * @var array<int, string|null>
     */
    protected array $connectionsToTransact = [null, 'vox'];
}
