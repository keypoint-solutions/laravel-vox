<?php

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use KeypointSolutions\LaravelVox\Database\Factories\VoxTranslationFactory;
use KeypointSolutions\LaravelVox\Models\VoxEnvironment;

beforeEach(function (): void {
    config()->set('vox.translate.locales.mode', 'configured');
    config()->set('vox.translate.locales.values', ['en', 'fr']);
    config()->set('vox.translate.base_locale', 'en');

    $this->actingAs(User::factory()->create(['email' => 'admin@keypoint.ro']));
});

afterEach(function (): void {
    File::deleteDirectory(lang_path('de'));
    File::deleteDirectory(lang_path('vendor/otp/de'));
    File::delete(
        lang_path('de.json'),
        lang_path('en/vox_browser_sync.php'),
        lang_path('fr/vox_browser_sync.php'),
        lang_path('en/vox_browser_import.php'),
        lang_path('fr/vox_browser_import.php'),
        storage_path('vox/frontend-translations/de.json'),
        storage_path('framework/testing/vox-browser-remote.zip'),
        storage_path('framework/testing/vox-browser-import.zip'),
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
        ->assertSee('Download publishable files')
        ->assertSee('Import language files')
        ->assertNoJavaScriptErrors();
});

it('provisions a new application language from the source locale', function (): void {
    visit('/vox/sync')
        ->assertSee('Application languages')
        ->assertSee('English · en')
        ->fill('[data-test="sync-locale-code"]', 'de')
        ->pressAndWaitFor('Add language')
        ->assertSee('Added German (de)')
        ->assertSee('German · de')
        ->assertNoJavaScriptErrors();

    expect(File::isFile(lang_path('de/frontend.php')))->toBeTrue()
        ->and((require lang_path('de/frontend.php'))['Regular translation'])
        ->toStartWith('🚩');
});

it('selects a translation archive and enables the import action', function (): void {
    $archivePath = storage_path('framework/testing/vox-browser-import.zip');
    File::ensureDirectoryExists(dirname($archivePath));
    $archive = new ZipArchive;
    $archive->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $archive->addFromString(
        'en/vox_browser_import.php',
        "<?php\n\nreturn ['message' => 'Imported without sync'];\n"
    );
    $archive->addFromString(
        'fr/vox_browser_import.php',
        "<?php\n\nreturn ['message' => 'Importé sans synchronisation'];\n"
    );
    $archive->close();

    $encodedArchive = json_encode(base64_encode(File::get($archivePath)), JSON_THROW_ON_ERROR);
    $page = visit('/vox/sync');
    $page->script(
        "() => {
            const bytes = Uint8Array.from(atob({$encodedArchive}), (character) => character.charCodeAt(0));
            const transfer = new DataTransfer();
            transfer.items.add(new File([bytes], 'vox-browser-import.zip', { type: 'application/zip' }));
            const input = document.querySelector('[name=\"archive\"]');
            input.files = transfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }"
    );

    expect($page->script(
        "() => ({
            files: document.querySelector('[name=\"archive\"]').files.length,
            filename: document.querySelector('[name=\"archive\"]').files[0]?.name,
            disabled: document.querySelector('[data-test=\"sync-archive-import\"]').disabled,
        })"
    ))->toMatchArray([
        'files' => 1,
        'filename' => 'vox-browser-import.zip',
        'disabled' => false,
    ]);

    $page->assertNoJavaScriptErrors();
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
