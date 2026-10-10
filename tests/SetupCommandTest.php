<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\LaravelVoxServiceProvider;
use KeypointSolutions\LaravelVox\Support\VoxDatabaseManager;
use KeypointSolutions\LaravelVox\Support\VoxFrontendDependency;

beforeEach(function (): void {
    $this->fixtureRoot = __DIR__.'/.tmp/setup-'.Str::uuid();
    $this->app->usePublicPath($this->fixtureRoot.'/public');
    config()->set('vox.database.connection', 'vox_setup');
    config()->set('vox.database.path', $this->fixtureRoot.'/database/vox.sqlite');
    config()->set('database.connections.vox_setup', null);
    (new LaravelVoxServiceProvider($this->app))->packageBooted();
});

afterEach(function (): void {
    DB::purge('vox_setup');
});

it('registers the connection during provider boot without creating files', function (): void {
    (new LaravelVoxServiceProvider($this->app))->packageBooted();

    expect(config('database.connections.vox_setup.driver'))->toBe('sqlite')
        ->and(config('database.connections.vox_setup.database'))->toBe(config('vox.database.path'))
        ->and(File::exists($this->fixtureRoot))->toBeFalse();
});

it('sets up the database and assets and can safely run again', function (): void {
    $this->artisan('vox:setup', ['--force' => true])->assertSuccessful();

    expect(File::exists(config('vox.database.path')))->toBeTrue()
        ->and(Schema::connection('vox_setup')->hasTable('vox_translations'))->toBeTrue()
        ->and(File::exists(public_path('vendor/vox/manifest.json')))->toBeTrue();

    $migrations = DB::connection('vox_setup')->table('migrations')->count();
    $this->artisan('vox:setup', ['--force' => true])->assertSuccessful();

    expect(DB::connection('vox_setup')->table('migrations')->count())->toBe($migrations);
});

it('keeps the install command as a setup alias', function (): void {
    $this->artisan('vox:install', ['--force' => true])->assertSuccessful();

    expect(Schema::connection('vox_setup')->hasTable('vox_translations'))->toBeTrue()
        ->and(File::exists(public_path('vendor/vox/manifest.json')))->toBeTrue();
});

it('uses an existing sqlite connection even without a vox database path', function (): void {
    $databasePath = $this->fixtureRoot.'/existing.sqlite';
    config()->set('vox.database.path', null);
    config()->set('database.connections.vox_setup', [
        'driver' => 'sqlite',
        'database' => $databasePath,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    $this->artisan('vox:setup', ['--force' => true])->assertSuccessful();

    expect(File::exists($databasePath))->toBeTrue()
        ->and(Schema::connection('vox_setup')->hasTable('vox_translations'))->toBeTrue();
});

it('does not create a sqlite file for an existing non-sqlite connection', function (): void {
    $connection = ['driver' => 'mysql', 'database' => 'vox'];
    config()->set('database.connections.vox_setup', $connection);

    app(VoxDatabaseManager::class)->initializeDatabase();

    expect(config('database.connections.vox_setup'))->toBe($connection)
        ->and(File::exists($this->fixtureRoot))->toBeFalse();
});

it('reports an unconfigured connection without creating files', function (): void {
    config()->set('vox.database.path', null);
    config()->set('database.connections.vox_setup', null);

    $this->artisan('vox:setup', ['--force' => true])->assertFailed();

    expect(File::exists($this->fixtureRoot))->toBeFalse();
});

it('keeps application migrations separate from Vox setup', function (): void {
    $this->artisan('vox:setup', ['--force' => true])->assertSuccessful();
    $migrations = DB::connection('vox_setup')->table('migrations')->count();

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    expect(DB::connection('vox_setup')->table('migrations')->count())->toBe($migrations);
});

function useVoxFrontendApplication(string $root, array $manifest, ?string $lockfile = null): string
{
    File::ensureDirectoryExists($root);
    File::put($root.'/package.json', json_encode($manifest));

    if ($lockfile !== null) {
        File::put($root.'/'.$lockfile, '');
    }

    app()->instance(VoxFrontendDependency::class, new VoxFrontendDependency($root));
    Process::fake();

    return json_decode(File::get(__DIR__.'/../package.json'), true)['dependencies']['laravel-vue-i18n'];
}

it('reports the missing Vue translation dependency without installing it during unattended setup', function (): void {
    $range = useVoxFrontendApplication($this->fixtureRoot, ['dependencies' => ['vue' => '^3.5']]);

    $this->artisan('vox:setup', ['--force' => true])
        ->expectsOutputToContain('Install it with: npm install "laravel-vue-i18n@'.$range.'"')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('installs the missing Vue translation dependency with the application package manager when confirmed', function (): void {
    $range = useVoxFrontendApplication($this->fixtureRoot, ['devDependencies' => ['vue' => '^3.5']], 'pnpm-lock.yaml');
    $command = 'pnpm add "laravel-vue-i18n@'.$range.'"';

    $this->artisan('vox:setup')
        ->expectsConfirmation("Vox's Vue integration needs laravel-vue-i18n. Run {$command} now?", 'yes')
        ->expectsOutputToContain('Installed laravel-vue-i18n.')
        ->assertSuccessful();

    Process::assertRan(fn ($process): bool => $process->command === ['pnpm', 'add', 'laravel-vue-i18n@'.$range]
        && $process->path === $this->fixtureRoot);
});

it('leaves npm dependencies alone when the installation is declined', function (): void {
    $range = useVoxFrontendApplication($this->fixtureRoot, ['dependencies' => ['vue' => '^3.5']]);
    $command = 'npm install "laravel-vue-i18n@'.$range.'"';

    $this->artisan('vox:setup')
        ->expectsConfirmation("Vox's Vue integration needs laravel-vue-i18n. Run {$command} now?", 'no')
        ->expectsOutputToContain("Install it with: {$command}")
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('does not ask for the Vue translation dependency when it is not needed', function (array $manifest): void {
    useVoxFrontendApplication($this->fixtureRoot, $manifest);

    $this->artisan('vox:setup')->assertSuccessful();

    expect(app(VoxFrontendDependency::class)->isMissing())->toBeFalse();
    Process::assertNothingRan();
})->with([
    'no Vue' => [['dependencies' => ['alpinejs' => '^3.0']]],
    'already installed' => [['dependencies' => ['vue' => '^3.5'], 'devDependencies' => ['laravel-vue-i18n' => '^2.8']]],
    'Vox npm package' => [['dependencies' => ['vue' => '^3.5', '@keypoint-solutions/laravel-vox' => '^1.0']]],
]);
