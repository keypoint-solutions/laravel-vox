<?php

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use KeypointSolutions\LaravelVox\Database\Factories\VoxTranslationFactory;
use KeypointSolutions\LaravelVox\Models\VoxEnvironment;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Translation\RemoteReconciliation;

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

it('pulls production candidates and accepts them without changing local files before publishing', function (): void {
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

    $page = visit('/vox/sync')
        ->assertSee('Browser production')
        ->pressAndWaitFor('Pull now')
        ->assertSee('Local translations and files were not changed')
        ->assertSee('Review remote changes')
        ->assertSee('Local stale value')
        ->assertSee('Production value')
        ->assertNoJavaScriptErrors();

    $english = require lang_path('en/vox_browser_sync.php');

    expect($english['message'])->toBe('Local stale value')
        ->and($english['local_only'])->toBe('Keep me')
        ->and($translation->fresh()->status)->toBe('approved');

    $page->click('[data-test="review-select-all"]')
        ->assertSee('2 selected across all pages')
        ->click('[data-test="review-bulk-accept"]')
        ->assertSee('Accepted 2 values into pending review')
        ->assertSee('No remote changes match these filters')
        ->assertNoJavaScriptErrors();

    expect((require lang_path('en/vox_browser_sync.php'))['message'])->toBe('Local stale value')
        ->and($translation->values()->where('locale', 'en')->first()->value)->toBe('Production value')
        ->and($translation->fresh()->status)->toBe('pending');
});

it('filters expected changes and accepts every matching value across pages without accepting conflicts', function (): void {
    $environment = VoxEnvironment::query()->create([
        'name' => 'Bulk production', 'type' => 'production',
        'url' => 'https://bulk.example.test', 'secret_key' => 'bulk-secret',
    ]);
    $values = [];
    for ($index = 0; $index < 65; $index++) {
        $values[] = ['group' => 'bulk_review', 'key' => 'expected_'.$index, 'locale' => 'en', 'value' => 'Expected value '.$index];
    }
    $conflict = VoxTranslationFactory::new()->withValues(['en' => 'Local conflict'])->create([
        'group' => 'bulk_review', 'key' => 'conflict',
    ]);
    $values[] = ['group' => 'bulk_review', 'key' => 'conflict', 'locale' => 'en', 'value' => 'Remote conflict'];
    app(RemoteReconciliation::class)->ingest($environment, $values);

    visit('/vox/sync')
        ->assertSee('Review remote changes')
        ->select('#review-state', 'incoming')
        ->pressAndWaitFor('Filter changes')
        ->assertSee('65 values')
        ->assertSee('Page 1 of 2')
        ->click('[data-test="review-select-all"]')
        ->assertSee('65 selected across all pages')
        ->click('[data-test="review-bulk-accept"]')
        ->assertSee('Accepted 65 values into pending review')
        ->assertNoJavaScriptErrors();

    expect(VoxTranslation::query()->where('group', 'bulk_review')->where('key', 'like', 'expected_%')->count())->toBe(65)
        ->and($conflict->values()->first()->value)->toBe('Local conflict')
        ->and(app(RemoteReconciliation::class)->unresolvedCount())->toBe(1);
});

it('can keep local wording and edit a merged value from the review panel', function (): void {
    $environment = VoxEnvironment::query()->create([
        'name' => 'Review production', 'type' => 'production',
        'url' => 'https://review.example.test', 'secret_key' => 'review-secret',
    ]);
    $translation = VoxTranslationFactory::new()->approved()->withValues(['en' => 'Local wording'])->create([
        'group' => 'review_demo', 'key' => 'wording',
    ]);
    app(RemoteReconciliation::class)->ingest($environment, [[
        'group' => 'review_demo', 'key' => 'wording', 'locale' => 'en', 'value' => 'Remote wording',
    ]]);

    $page = visit('/vox/sync')
        ->pressAndWaitFor('Keep local')
        ->assertSee('Kept local values for 1 remote changes')
        ->select('#review-state', 'outgoing')
        ->pressAndWaitFor('Filter changes')
        ->assertSee('Local wording')
        ->press('Edit merged value')
        ->fill('#review-edited-value', 'Merged wording')
        ->pressAndWaitFor('Save merged value')
        ->assertSee('Accepted 1 values into pending review')
        ->assertNoJavaScriptErrors();

    expect($translation->values()->first()->value)->toBe('Merged wording')
        ->and($translation->fresh()->status)->toBe('pending');
});

it('retains individual selections across pages and clears them when filters change', function (): void {
    $environment = VoxEnvironment::query()->create([
        'name' => 'Paged review', 'type' => 'production',
        'url' => 'https://paged.example.test', 'secret_key' => 'paged-secret',
    ]);
    $values = [];
    for ($index = 0; $index < 55; $index++) {
        $values[] = ['group' => 'paged_review', 'key' => 'key_'.$index, 'locale' => 'en', 'value' => 'Paged value '.$index];
    }
    app(RemoteReconciliation::class)->ingest($environment, $values);

    visit('/vox/sync')
        ->click('button[aria-label="Select paged_review.key_0 en"]')
        ->assertSee('1 selected')
        ->pressAndWaitFor('Next')
        ->assertSee('Page 2 of 2')
        ->click('button[aria-label="Select paged_review.key_50 en"]')
        ->assertSee('2 selected')
        ->click('[data-test="review-bulk-keep"]')
        ->assertSee('Kept local values for 2 remote changes')
        ->click('[data-test="review-select-all"]')
        ->assertSee('53 selected across all pages')
        ->fill('#review-search', 'key_54')
        ->assertSee('Apply your filters before selecting changes')
        ->assertSee('0 selected')
        ->pressAndWaitFor('Filter changes')
        ->assertSee('1 values')
        ->resize(390, 844)
        ->assertNoJavaScriptErrors();

    expect(app(RemoteReconciliation::class)->unresolvedCount())->toBe(53)
        ->and(VoxTranslation::query()->where('group', 'paged_review')->count())->toBe(0);
});
