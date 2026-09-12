<?php

namespace KeypointSolutions\LaravelVox\Commands;

use Illuminate\Console\Command;
use KeypointSolutions\LaravelVox\Support\VoxDatabaseManager;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class SetupCommand extends Command
{
    public $signature = 'vox:setup {--force : Run setup in production}';

    protected $aliases = ['vox:install'];

    public $description = 'Prepare the Vox database, run migrations, and publish dashboard assets.';

    public function handle(): int
    {
        $connection = (string) config('vox.database.connection', 'vox');
        app(VoxDatabaseManager::class)->ensureConnection();
        $databasePath = config("database.connections.{$connection}.database");

        if (config("database.connections.{$connection}") === null) {
            warning('Vox database connection is not configured. Check vox.database settings.');

            return self::FAILURE;
        }

        app(VoxDatabaseManager::class)->initializeDatabase();

        $migrationPath = realpath(__DIR__.'/../../database/migrations');

        if ($migrationPath === false) {
            warning('Unable to locate Vox migrations.');

            return self::FAILURE;
        }

        $exitCode = spin(
            fn () => $this->call('migrate', [
                '--database' => $connection,
                '--path' => $migrationPath,
                '--realpath' => true,
                '--force' => (bool) $this->option('force'),
            ]),
            'Running Vox migrations'
        );

        if ($exitCode !== self::SUCCESS) {
            warning('Vox setup failed.');

            return $exitCode;
        }

        $assetExitCode = spin(
            fn () => $this->call('vendor:publish', [
                '--tag' => 'vox-assets',
                '--force' => true,
            ]),
            'Publishing Vox dashboard assets'
        );

        if ($assetExitCode !== self::SUCCESS) {
            warning('Vox asset publishing failed.');

            return $assetExitCode;
        }

        info('Vox setup complete.');
        table(['Setting', 'Value'], [
            ['Database connection', $connection],
            ['Database path', $databasePath],
            ['Migrations path', $migrationPath],
        ]);

        return self::SUCCESS;
    }
}
