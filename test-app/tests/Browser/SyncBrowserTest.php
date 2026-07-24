<?php

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use KeypointSolutions\LaravelVox\Database\Factories\VoxTranslationFactory;
use KeypointSolutions\LaravelVox\Models\VoxEnvironment;

beforeEach(function (): void {
    config()->set('vox.translate.locales', ['en', 'fr']);
    config()->set('vox.translate.base_locale', 'en');

    $this->actingAs(User::factory()->create(['email' => 'admin@keypoint.ro']));
});

afterEach(function (): void {
    File::delete(
        lang_path('en/vox_browser_sync.php'),
        lang_path('fr/vox_browser_sync.php'),
        storage_path('framework/testing/vox-browser-remote.zip'),
    );
});

it('asks whether to update language files before local sync', function (): void {
    visit('/vox/sync')
        ->press('Sync local files')
        ->assertSee('Update language files from source first?')
        ->assertSee('Sync files as they are')
        ->assertSee('Update files & sync')
        ->pressAndWaitFor('Sync files as they are')
        ->assertSee('Synchronized')
        ->assertNoJavaScriptErrors();
});

it('pulls production values, preserves local-only keys, and reopens conflicts', function (): void {
    File::ensureDirectoryExists(lang_path('en'));
    File::ensureDirectoryExists(lang_path('fr'));
    File::put(
        lang_path('en/vox_browser_sync.php'),
        "<?php\n\nreturn ['message' => 'Local stale value', 'local_only' => 'Keep me'];\n"
    );
    File::put(
        lang_path('fr/vox_browser_sync.php'),
        "<?php\n\nreturn ['message' => 'Valeur locale obsolète', 'local_only' => 'Gardez-moi'];\n"
    );

    $translation = VoxTranslationFactory::new()
        ->approved()
        ->withValues([
            'en' => 'Local stale value',
            'fr' => 'Valeur locale obsolète',
        ])
        ->create(['group' => 'vox_browser_sync', 'key' => 'message']);

    VoxEnvironment::query()->create([
        'name' => 'Browser production',
        'type' => 'production',
        'url' => 'https://browser-remote.example.test/vox/sync',
        'secret_key' => 'browser-secret',
    ]);

    $archivePath = storage_path('framework/testing/vox-browser-remote.zip');
    File::ensureDirectoryExists(dirname($archivePath));
    $archive = new ZipArchive;
    $archive->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $archive->addFromString(
        'en/vox_browser_sync.php',
        "<?php\n\nreturn ['message' => 'Production value'];\n"
    );
    $archive->addFromString(
        'fr/vox_browser_sync.php',
        "<?php\n\nreturn ['message' => 'Valeur de production'];\n"
    );
    $archive->close();

    Http::fake([
        'https://browser-remote.example.test/vox/sync' => Http::response(
            File::get($archivePath),
            200,
            ['Content-Type' => 'application/zip']
        ),
    ]);

    visit('/vox/sync')
        ->assertSee('Browser production')
        ->pressAndWaitFor('Pull now')
        ->assertSee('approved translation was returned to review')
        ->assertNoJavaScriptErrors();

    $english = require lang_path('en/vox_browser_sync.php');

    expect($english['message'])->toBe('Production value')
        ->and($english['local_only'])->toBe('Keep me')
        ->and($translation->fresh()->status)->toBe('pending');
});
