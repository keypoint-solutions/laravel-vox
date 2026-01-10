<?php

namespace KeypointSolutions\LaravelVox;

use KeypointSolutions\LaravelVox\Commands\CleanupCommand;
use KeypointSolutions\LaravelVox\Commands\GenerateSyncKeyCommand;
use KeypointSolutions\LaravelVox\Commands\InstallCommand;
use KeypointSolutions\LaravelVox\Commands\ParseTranslationsCommand;
use KeypointSolutions\LaravelVox\Commands\SettingsCommand;
use KeypointSolutions\LaravelVox\Commands\SyncRemoteTranslationsCommand;
use KeypointSolutions\LaravelVox\Commands\SyncTranslationsCommand;
use KeypointSolutions\LaravelVox\Commands\TranslateMissingTranslationsCommand;
use KeypointSolutions\LaravelVox\Support\VoxDatabaseManager;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelVoxServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-vox')
            ->hasConfigFile()
            ->hasViews();

        if (config('vox.gui.enabled')) {
            $package->hasRoute('web');
        }
    }

    public function packageBooted(): void
    {
        app(VoxDatabaseManager::class)->ensureConnection();
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CleanupCommand::class,
                InstallCommand::class,
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
