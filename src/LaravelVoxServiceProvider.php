<?php

namespace KeypointSolutions\LaravelVox;

use Illuminate\Console\Scheduling\Schedule;
use KeypointSolutions\LaravelVox\Ai\AiModelDiscovery;
use KeypointSolutions\LaravelVox\Ai\ClaudeModelDiscovery;
use KeypointSolutions\LaravelVox\Ai\OpenAiModelDiscovery;
use KeypointSolutions\LaravelVox\Ai\UnavailableAiModelDiscovery;
use KeypointSolutions\LaravelVox\Commands\CheckpointCommand;
use KeypointSolutions\LaravelVox\Commands\CleanupCommand;
use KeypointSolutions\LaravelVox\Commands\CompileCommand;
use KeypointSolutions\LaravelVox\Commands\DeployCommand;
use KeypointSolutions\LaravelVox\Commands\DiscoverFrontendCommand;
use KeypointSolutions\LaravelVox\Commands\GenerateSyncKeyCommand;
use KeypointSolutions\LaravelVox\Commands\ParseTranslationsCommand;
use KeypointSolutions\LaravelVox\Commands\PruneCheckpointsCommand;
use KeypointSolutions\LaravelVox\Commands\PublishCommand;
use KeypointSolutions\LaravelVox\Commands\ResetCommand;
use KeypointSolutions\LaravelVox\Commands\ReviewCommand;
use KeypointSolutions\LaravelVox\Commands\SettingsCommand;
use KeypointSolutions\LaravelVox\Commands\SetupCommand;
use KeypointSolutions\LaravelVox\Commands\SyncRemoteTranslationsCommand;
use KeypointSolutions\LaravelVox\Commands\SyncTranslationsCommand;
use KeypointSolutions\LaravelVox\Commands\TranslateMissingTranslationsCommand;
use KeypointSolutions\LaravelVox\Support\VoxConfig;
use KeypointSolutions\LaravelVox\Support\VoxDatabaseManager;
use KeypointSolutions\LaravelVox\Support\VoxMutationLock;
use KeypointSolutions\LaravelVox\Support\VoxSettingsRepository;
use KeypointSolutions\LaravelVox\Translation\Files\TranslationFileTransaction;
use KeypointSolutions\LaravelVox\Translation\Scanning\VoxDynamicKeyRegistry;
use KeypointSolutions\LaravelVox\Translation\TranslationCheckpoints;
use KeypointSolutions\LaravelVox\Translation\TranslationFallbackRules;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelVoxServiceProvider extends PackageServiceProvider
{
    public function packageRegistered(): void
    {
        $this->app->scoped(TranslationFallbackRules::class);
        $this->app->singleton(TranslationCheckpoints::class);
        $this->app->singleton(VoxMutationLock::class);
        $this->app->singleton(TranslationFileTransaction::class);
        $this->app->singleton(
            VoxDynamicKeyRegistry::class,
            fn ($app): VoxDynamicKeyRegistry => new VoxDynamicKeyRegistry(
                $app->make(VoxSettingsRepository::class)
            )
        );

        $this->app->bind(AiModelDiscovery::class, function ($app): AiModelDiscovery {
            if (VoxConfig::translateDriver() === 'openai') {
                return $app->make(OpenAiModelDiscovery::class);
            }

            if (VoxConfig::translateDriver() === 'claude') {
                return $app->make(ClaudeModelDiscovery::class);
            }

            return $app->make(UnavailableAiModelDiscovery::class);
        });
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-vox')
            ->hasConfigFile()
            ->hasViews()
            ->hasRoute('web');
    }

    public function packageBooted(): void
    {
        app(VoxDatabaseManager::class)->ensureConnection();
        $this->publishes([
            __DIR__.'/../dist/vox' => public_path('vendor/vox'),
        ], 'vox-assets');

        $this->callAfterResolving(Schedule::class, function ($schedule): void {
            $schedule->command('vox:checkpoint-prune')->daily()->withoutOverlapping();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckpointCommand::class,
                PruneCheckpointsCommand::class,
                CleanupCommand::class,
                ResetCommand::class,
                SetupCommand::class,
                DeployCommand::class,
                CompileCommand::class,
                DiscoverFrontendCommand::class,
                PublishCommand::class,
                ReviewCommand::class,
                ParseTranslationsCommand::class,
                TranslateMissingTranslationsCommand::class,
                SyncTranslationsCommand::class,
                SyncRemoteTranslationsCommand::class,
                SettingsCommand::class,
                GenerateSyncKeyCommand::class,
            ]);
        }
    }
}
