<?php

namespace KeypointSolutions\LaravelVox\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\ServiceProvider;
use KeypointSolutions\LaravelVox\LaravelVoxServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected ?string $voxDatabasePath = null;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'KeypointSolutions\\LaravelVox\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        $connection = 'vox_'.Str::uuid()->toString();
        $this->voxDatabasePath = base_path('tests/.tmp/vox-'.Str::uuid().'.sqlite');
        $directory = dirname($this->voxDatabasePath);

        if (! File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        if (! File::exists($this->voxDatabasePath)) {
            File::put($this->voxDatabasePath, '');
        }

        config()->set('vox.database.connection', $connection);
        config()->set('vox.database.path', $this->voxDatabasePath);
        config()->set("database.connections.{$connection}", [
            'driver' => 'sqlite',
            'database' => $this->voxDatabasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge($connection);
        DB::reconnect($connection);

        $this->artisan('migrate', ['--database' => $connection])->run();
    }

    protected function tearDown(): void
    {
        if ($this->voxDatabasePath !== null && File::exists($this->voxDatabasePath)) {
            File::delete($this->voxDatabasePath);
        }

        parent::tearDown();
    }

    protected function getPackageProviders($app)
    {
        return [
            ServiceProvider::class,
            LaravelVoxServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('app.key', 'base64:2fl+Ktvkfl+Fuz4Qp/A75G2RTiWVA/ZoKZvp6fiiM10=');
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        config()->set('cache.default', 'array');
    }
}
