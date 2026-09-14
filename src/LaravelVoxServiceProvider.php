<?php

namespace KeypointSolutions\LaravelVox;

use KeypointSolutions\LaravelVox\Commands\CleanupCommand;
use KeypointSolutions\LaravelVox\Commands\CompileCommand;
use KeypointSolutions\LaravelVox\Commands\DeployCommand;
use KeypointSolutions\LaravelVox\Commands\GenerateSyncKeyCommand;
use KeypointSolutions\LaravelVox\Commands\ParseTranslationsCommand;
use KeypointSolutions\LaravelVox\Commands\PublishCommand;
use KeypointSolutions\LaravelVox\Commands\ResetCommand;
use KeypointSolutions\LaravelVox\Commands\ReviewCommand;
use KeypointSolutions\LaravelVox\Commands\SettingsCommand;
use KeypointSolutions\LaravelVox\Commands\SetupCommand;
use KeypointSolutions\LaravelVox\Commands\SyncRemoteTranslationsCommand;
use KeypointSolutions\LaravelVox\Commands\SyncTranslationsCommand;
use KeypointSolutions\LaravelVox\Commands\TranslateMissingTranslationsCommand;
use KeypointSolutions\LaravelVox\Support\AiModelDiscovery;
use KeypointSolutions\LaravelVox\Support\OpenAiModelDiscovery;
use KeypointSolutions\LaravelVox\Support\UnavailableAiModelDiscovery;
use KeypointSolutions\LaravelVox\Support\VoxDatabaseManager;
use KeypointSolutions\LaravelVox\Support\VoxDynamicKeyRegistry;
use KeypointSolutions\LaravelVox\Support\VoxMutationLock;
use KeypointSolutions\LaravelVox\Support\VoxSettingsRepository;
use KeypointSolutions\LaravelVox\Translation\TranslationFileTransaction;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelVoxServiceProvider extends PackageServiceProvider
{
    public function packageRegistered(): void
    {
        $this->app->singleton(VoxMutationLock::class);
        $this->app->singleton(TranslationFileTransaction::class);
        $this->app->singleton(
            VoxDynamicKeyRegistry::class,
            fn ($app): VoxDynamicKeyRegistry => new VoxDynamicKeyRegistry(
                $app->make(VoxSettingsRepository::class)
            )
        );

        $this->app->bind(AiModelDiscovery::class, function ($app): AiModelDiscovery {
            if (config('vox.translate.driver', 'openai') === 'openai') {
                return $app->make(OpenAiModelDiscovery::class);
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
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->publishes([
            __DIR__.'/../dist/vox' => public_path('vendor/vox'),
        ], 'vox-assets');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CleanupCommand::class,
                ResetCommand::class,
                SetupCommand::class,
                DeployCommand::class,
                CompileCommand::class,
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
