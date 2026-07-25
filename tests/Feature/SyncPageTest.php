<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use KeypointSolutions\LaravelVox\Models\VoxAudit;
use KeypointSolutions\LaravelVox\Models\VoxEnvironment;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Translation\TranslationFileRepository;
use KeypointSolutions\LaravelVox\Translation\TranslationFileWriter;

beforeEach(function (): void {
    $this->withoutVite();
    $this->withoutMiddleware(PreventRequestForgery::class);
    app()->detectEnvironment(fn () => 'local');
    config()->set('vox.system.bypass_auth_in_local', true);
    config()->set('vox.translate.locales', ['en', 'fr']);
    config()->set('vox.translate.base_locale', 'en');
    config()->set('vox.parse.paths', []);

    $this->syncRoot = base_path('tests/.tmp/sync-'.Str::uuid());
    $this->syncLangPath = $this->syncRoot.'/lang';
    File::makeDirectory($this->syncLangPath, 0755, true);
    config()->set('vox.paths.lang', $this->syncLangPath);
    config()->set('vox.frontend.manifest', $this->syncRoot.'/frontend.json');
});

afterEach(function (): void {
    if (File::isDirectory($this->syncRoot)) {
        File::deleteDirectory($this->syncRoot);
    }
});

function remoteTranslationArchive(string $root): string
{
    $archivePath = $root.'/remote.zip';
    $zip = new ZipArchive;
    $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('en/vox_remote_demo.php', "<?php\n\nreturn ['message' => 'Remote message'];\n");
    $zip->addFromString('fr/vox_remote_demo.php', "<?php\n\nreturn ['message' => 'Message distant'];\n");
    $zip->close();

    return File::get($archivePath);
}

it('synchronizes local language files into the database from the UI', function (): void {
    $files = new TranslationFileRepository(new TranslationFileWriter);
    $files->saveGroup('en', 'local_demo', ['message' => 'Local message']);
    $files->saveGroup('fr', 'local_demo', ['message' => 'Message local']);

    $this->from('/vox/sync')
        ->post('/vox/sync/local')
        ->assertRedirect('/vox/sync')
        ->assertInertiaFlash('success', 'Synchronized 1 local translation.');

    expect(VoxTranslation::query()
        ->where('group', 'local_demo')
        ->where('key', 'message')
        ->exists())->toBeTrue()
        ->and(VoxAudit::query()->where('action', 'sync')->exists())->toBeTrue()
        ->and(VoxAudit::query()->where('action', 'sync')->firstOrFail()->context['started_at'])
        ->toBeString();
});

it('can update language files from the source scan before local sync', function (): void {
    $sourcePath = $this->syncRoot.'/resources/views';
    File::makeDirectory($sourcePath, 0755, true);
    File::put($sourcePath.'/demo.blade.php', "{{ __('source_demo.message') }}");
    config()->set('vox.parse.paths', [$sourcePath]);

    $this->from('/vox/sync')
        ->post('/vox/sync/local', ['update_language_files' => true])
        ->assertRedirect('/vox/sync')
        ->assertInertiaFlash(
            'success',
            'Synchronized 1 local translation. Language files were updated first (2 added, 0 removed).'
        );

    expect(require $this->syncLangPath.'/en/source_demo.php')
        ->toHaveKey('message')
        ->and(require $this->syncLangPath.'/fr/source_demo.php')
        ->toHaveKey('message')
        ->and(VoxTranslation::query()
            ->where('group', 'source_demo')
            ->where('key', 'message')
            ->exists())->toBeTrue();
});

it('manages remote environments without exposing saved secrets', function (): void {
    $this->from('/vox/sync')->post('/vox/sync/environments', [
        'name' => 'Demo remote',
        'type' => 'testing',
        'url' => 'https://remote.example.test',
        'secret_key' => 'secret-value',
    ])->assertRedirect('/vox/sync')
        ->assertInertiaFlash('success', 'Environment added.');

    $environment = VoxEnvironment::query()->firstOrFail();

    $this->get('/vox/sync')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Sync', false)
            ->has('environments', 1)
            ->where('environments.0.name', 'Demo remote')
            ->where('environments.0.secret_key_set', true)
            ->missing('environments.0.secret_key')
        );

    $this->put("/vox/sync/environments/{$environment->id}", [
        'name' => 'Updated remote',
        'type' => 'staging',
        'url' => 'https://staging.example.test/vox/sync',
        'secret_key' => '',
    ])->assertRedirect('/vox/sync');

    expect($environment->fresh())
        ->name->toBe('Updated remote')
        ->secret_key->toBe('secret-value');
});

it('pulls a remote archive and synchronizes it into the local database', function (): void {
    $files = new TranslationFileRepository(new TranslationFileWriter);
    $files->saveGroup('en', 'vox_remote_demo', [
        'message' => 'Stale repository message',
        'local_only' => 'New local key',
    ]);
    $files->saveGroup('fr', 'vox_remote_demo', [
        'message' => 'Message obsolète du dépôt',
        'local_only' => 'Nouvelle clé locale',
    ]);
    $approvedTranslation = VoxTranslation::factory()
        ->approved()
        ->withValues([
            'en' => 'Stale repository message',
            'fr' => 'Message obsolète du dépôt',
        ])
        ->create(['group' => 'vox_remote_demo', 'key' => 'message']);

    $environment = VoxEnvironment::query()->create([
        'name' => 'Demo remote',
        'type' => 'testing',
        'url' => 'https://remote.example.test/vox/sync',
        'secret_key' => 'secret-value',
    ]);

    Http::fake([
        'https://remote.example.test/vox/sync' => Http::response(
            remoteTranslationArchive($this->syncRoot),
            200,
            ['Content-Type' => 'application/zip']
        ),
    ]);

    $this->from('/vox/sync')
        ->post("/vox/sync/environments/{$environment->id}/pull")
        ->assertRedirect('/vox/sync')
        ->assertInertiaFlash(
            'success',
            'Pulled 2 translations from Demo remote. 1 approved translation was returned to review.'
        );

    $mergedEnglish = require $this->syncLangPath.'/en/vox_remote_demo.php';

    expect($mergedEnglish['message'])->toBe('Remote message')
        ->and($mergedEnglish['local_only'])->toBe('New local key')
        ->and($approvedTranslation->fresh()->status)->toBe('pending')
        ->and(VoxTranslation::query()
            ->where('group', 'vox_remote_demo')
            ->where('key', 'message')
            ->exists())->toBeTrue()
        ->and(VoxAudit::query()->where('action', 'sync-remote')->exists())->toBeTrue();

    Http::assertSent(fn ($request): bool => $request->hasHeader('X-Vox-Key', 'secret-value'));
});

it('downloads the would-be published translations without changing local files', function (): void {
    $files = new TranslationFileRepository(new TranslationFileWriter);
    $files->saveGroup('en', 'messages', ['greeting' => 'Old English']);
    $files->saveGroup('fr', 'messages', ['greeting' => 'Ancien français']);

    VoxTranslation::factory()
        ->approved()
        ->withValues([
            'en' => 'Downloaded English',
            'fr' => 'Français téléchargé',
        ])
        ->create(['group' => 'messages', 'key' => 'greeting']);

    $response = $this->get('/vox/sync/archive')
        ->assertOk()
        ->assertDownload();
    $archivePath = $response->baseResponse->getFile()->getPathname();
    $zip = new ZipArchive;

    expect($zip->open($archivePath))->toBeTrue();
    $englishArchive = $zip->getFromName('en/messages.php');
    $frenchArchive = $zip->getFromName('fr/messages.php');
    $zip->close();

    expect($englishArchive)->toBeString()->toContain('Downloaded English')
        ->and($frenchArchive)->toBeString()->toContain('Français téléchargé')
        ->and(require $this->syncLangPath.'/en/messages.php')
        ->toBe(['greeting' => 'Old English']);
});

it('imports a validated translation archive without synchronizing the database', function (): void {
    $files = new TranslationFileRepository(new TranslationFileWriter);
    $files->saveGroup('en', 'import_demo', ['message' => 'Existing file']);
    $translation = VoxTranslation::factory()
        ->approved()
        ->withValues(['en' => 'Database value', 'fr' => 'Valeur de la base'])
        ->create(['group' => 'import_demo', 'key' => 'message']);

    $archivePath = $this->syncRoot.'/import.zip';
    $zip = new ZipArchive;
    $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('en/import_demo.php', "<?php\n\nreturn ['message' => 'Imported file'];\n");
    $zip->addFromString('fr/import_demo.php', "<?php\n\nreturn ['message' => 'Fichier importé'];\n");
    $zip->close();

    $this->from('/vox/sync')
        ->post('/vox/sync/archive', [
            'archive' => new UploadedFile(
                $archivePath,
                'translations.zip',
                'application/zip',
                null,
                true
            ),
        ])
        ->assertRedirect('/vox/sync')
        ->assertInertiaFlash(
            'success',
            'Imported 2 translation files. Run Local sync when you want to update the Vox database.'
        );

    expect(require $this->syncLangPath.'/en/import_demo.php')
        ->toBe(['message' => 'Imported file'])
        ->and($translation->fresh()->values->firstWhere('locale', 'en')?->value)
        ->toBe('Database value')
        ->and(VoxAudit::query()->where('action', 'translation-archive-imported')->exists())
        ->toBeTrue();
});

it('validates the complete archive before overwriting any translation file', function (): void {
    $files = new TranslationFileRepository(new TranslationFileWriter);
    $files->saveGroup('en', 'safe_demo', ['message' => 'Keep English']);
    $files->saveGroup('fr', 'safe_demo', ['message' => 'Garder français']);

    $archivePath = $this->syncRoot.'/unsafe-import.zip';
    $zip = new ZipArchive;
    $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('en/safe_demo.php', "<?php return ['message' => 'Would overwrite'];");
    $zip->addFromString('fr/safe_demo.php', "<?php return ['message' => strtoupper('unsafe')];");
    $zip->close();

    $this->from('/vox/sync')
        ->post('/vox/sync/archive', [
            'archive' => new UploadedFile(
                $archivePath,
                'translations.zip',
                'application/zip',
                null,
                true
            ),
        ])
        ->assertRedirect('/vox/sync')
        ->assertSessionHasErrors('archive');

    expect(require $this->syncLangPath.'/en/safe_demo.php')
        ->toBe(['message' => 'Keep English'])
        ->and(require $this->syncLangPath.'/fr/safe_demo.php')
        ->toBe(['message' => 'Garder français']);
});
