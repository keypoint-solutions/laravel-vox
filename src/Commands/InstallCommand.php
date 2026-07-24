<?php

namespace KeypointSolutions\LaravelVox\Commands;

use Illuminate\Console\Command;
use KeypointSolutions\LaravelVox\Support\VoxDatabaseManager;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class InstallCommand extends Command
{
    public $signature = 'vox:install {--force : Run the installation in production}';

    public $description = 'Create the Vox database and run package migrations.';

    public function handle(): int
    {
        $connection = (string) config('vox.database.connection', 'vox');
        $databasePath = config('vox.database.path');

        if (! is_string($databasePath) || $databasePath === '') {
            warning('Vox database path is not configured. Did you publish the config file?');

            return self::FAILURE;
        }

        app(VoxDatabaseManager::class)->ensureConnection();

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
            warning('Vox installation failed.');

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

        info('Vox installation complete.');
        table(['Setting', 'Value'], [
            ['Database connection', $connection],
            ['Database path', $databasePath],
            ['Migrations path', $migrationPath],
        ]);

        return self::SUCCESS;
    }
}
