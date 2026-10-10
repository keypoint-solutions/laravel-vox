<?php

namespace KeypointSolutions\LaravelVox\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use KeypointSolutions\LaravelVox\Support\VoxDatabaseManager;
use KeypointSolutions\LaravelVox\Support\VoxFrontendDependency;

use function Laravel\Prompts\confirm;
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

        $this->ensureFrontendDependency(app(VoxFrontendDependency::class));

        return self::SUCCESS;
    }

    /**
     * Unattended runs only report the command, so Composer hooks and deployments never change npm dependencies.
     */
    private function ensureFrontendDependency(VoxFrontendDependency $dependency): void
    {
        if (! $dependency->isMissing()) {
            return;
        }

        $command = $dependency->installCommandLine();
        $unattended = $this->option('force') || ! $this->input->isInteractive();

        if ($unattended || ! confirm("Vox's Vue integration needs laravel-vue-i18n. Run {$command} now?")) {
            warning("Vox's Vue integration needs laravel-vue-i18n. Install it with: {$command}");

            return;
        }

        $result = spin(
            fn () => Process::path($dependency->root())->timeout(300)->run($dependency->installCommand()),
            'Installing laravel-vue-i18n'
        );

        if ($result->failed()) {
            warning("Unable to install laravel-vue-i18n. Install it with: {$command}");

            return;
        }

        info('Installed laravel-vue-i18n.');
    }
}
