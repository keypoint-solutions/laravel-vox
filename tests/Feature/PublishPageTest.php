<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use KeypointSolutions\LaravelVox\Models\VoxAudit;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Translation\TranslationFileRepository;
use KeypointSolutions\LaravelVox\Translation\TranslationFileWriter;
use KeypointSolutions\LaravelVox\Translation\TranslationPublisher;

beforeEach(function (): void {
    $this->withoutVite();
    $this->withoutMiddleware(PreventRequestForgery::class);
    app()->detectEnvironment(fn () => 'local');
    config()->set('vox.system.bypass_auth_in_local', true);
    config()->set('vox.translate.locales.mode', 'configured');
    config()->set('vox.translate.locales.values', ['en', 'fr']);
    config()->set('vox.translate.base_locale', 'en');
    config()->set('vox.parse.missing_translation_prefix', '🚩');

    $this->publishLangPath = base_path('tests/.tmp/publish-'.Str::uuid());
    File::makeDirectory($this->publishLangPath, 0755, true);
    config()->set('vox.paths.lang', $this->publishLangPath);
});

afterEach(function (): void {
    if (File::isDirectory($this->publishLangPath)) {
        File::deleteDirectory($this->publishLangPath);
    }
});

function seedPublishTranslations(): void
{
    VoxTranslation::factory()
        ->approved()
        ->withValues(['en' => 'Published greeting', 'fr' => 'Salutation publiée'])
        ->create(['group' => 'messages', 'key' => 'greeting']);

    VoxTranslation::factory()
        ->pending()
        ->withValues(['en' => 'Pending database value', 'fr' => 'Valeur en attente'])
        ->create(['group' => 'messages', 'key' => 'pending']);

    VoxTranslation::factory()
        ->json()
        ->approved()
        ->withValues(['en' => 'Published JSON', 'fr' => 'JSON publié'])
        ->create(['key' => 'Publish example']);

    VoxTranslation::factory()
        ->approved()
        ->withValues(['en' => 'Complete', 'fr' => '🚩Incomplete'])
        ->create(['group' => 'messages', 'key' => 'incomplete']);
}

it('shows real publish readiness statistics', function (): void {
    seedPublishTranslations();

    $this->get('/vox/publish')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Publish', false)
            ->where('stats.approved', 3)
            ->where('stats.publishable', 3)
            ->where('stats.pending', 1)
            ->where('stats.incomplete', 1)
            ->where('stats.dynamic', 0)
            ->where('stats.orphan', 0)
        );
});

it('refreshes published files and runtime bundles with no publishable changes while preserving drafts', function (): void {
    config()->set('vox.frontend.runtime.enabled', true);
    config()->set('vox.frontend.runtime.path', $this->publishLangPath.'/runtime');
    config()->set('vox.frontend.groups', ['messages']);

    $translation = VoxTranslation::factory()->approved()->create(['group' => 'messages', 'key' => 'greeting']);
    $english = $translation->values()->create([
        'locale' => 'en', 'value' => 'Published greeting', 'file_value' => 'Original greeting',
        'published_override' => 'Published greeting', 'is_approved' => true, 'is_pending_publish' => false,
    ]);
    $french = $translation->values()->create([
        'locale' => 'fr', 'value' => 'Bonjour', 'file_value' => 'Bonjour',
        'is_approved' => true, 'is_pending_publish' => false,
    ]);
    $english->saveDraft('Unapproved greeting');
    $french->saveDraft('Brouillon');

    $this->get('/vox/publish')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Publish', false)->where('stats.publishable', 0));
    $this->post('/vox/publish')->assertRedirect();

    $files = new TranslationFileRepository(new TranslationFileWriter);
    expect($files->loadGroup('en', 'messages'))->toBe(['greeting' => 'Published greeting'])
        ->and($files->loadGroup('fr', 'messages'))->toBe(['greeting' => 'Bonjour'])
        ->and(json_decode(File::get($this->publishLangPath.'/runtime/en.json'), true)['messages.greeting'])->toBe('Published greeting')
        ->and($english->fresh()->value)->toBe('Unapproved greeting')
        ->and($english->fresh()->is_pending_publish)->toBeTrue()
        ->and($english->fresh()->is_approved)->toBeFalse()
        ->and($french->fresh()->value)->toBe('Brouillon')
        ->and($french->fresh()->published_override)->toBeNull();
});

it('publishes complete dynamic values while leaving orphans untouched', function (): void {
    config()->set('vox.retained_keys', [
        'messages.dynamic.*',
        'Dynamic JSON',
    ]);

    $files = new TranslationFileRepository(new TranslationFileWriter);

    foreach (['en', 'fr'] as $locale) {
        $files->saveGroup($locale, 'messages', [
            'dynamic' => [
                'notice' => $locale === 'en' ? 'File-owned notice' : 'Avis du fichier',
            ],
            'orphan' => $locale === 'en' ? 'Existing orphan' : 'Orphelin existant',
            'publishable' => 'Old',
        ]);
        $files->saveJson($locale, [
            'Dynamic JSON' => $locale === 'en' ? 'File-owned JSON' : 'JSON du fichier',
        ]);
    }

    VoxTranslation::factory()
        ->approved()
        ->withValues(['en' => 'Database notice', 'fr' => 'Avis de la base'])
        ->create(['group' => 'messages', 'key' => 'dynamic.notice']);
    VoxTranslation::factory()
        ->json()
        ->approved()
        ->withValues(['en' => 'Database JSON', 'fr' => 'JSON de la base'])
        ->create(['key' => 'Dynamic JSON']);
    VoxTranslation::factory()
        ->orphan()
        ->approved()
        ->withValues(['en' => 'Database orphan', 'fr' => 'Orphelin de la base'])
        ->create(['group' => 'messages', 'key' => 'orphan']);
    VoxTranslation::factory()
        ->approved()
        ->withValues(['en' => 'Published', 'fr' => 'Publié'])
        ->create(['group' => 'messages', 'key' => 'publishable']);

    $this->from('/vox/publish')
        ->post('/vox/publish')
        ->assertRedirect('/vox/publish')
        ->assertInertiaFlash('success', 'Published 6 translation values across 4 files.');

    $english = require $this->publishLangPath.'/en/messages.php';
    $englishJson = json_decode(File::get($this->publishLangPath.'/en.json'), true);
    $audit = VoxAudit::query()->where('action', 'publish')->latest('id')->firstOrFail();

    expect($english['dynamic']['notice'])->toBe('Database notice')
        ->and($english['orphan'])->toBe('Existing orphan')
        ->and($english['publishable'])->toBe('Published')
        ->and($englishJson['Dynamic JSON'])->toBe('Database JSON')
        ->and($audit->context)->not->toHaveKey('protected_translations')
        ->and($audit->context['orphan_translations'])->toBe(1);
});

it('publishes approved complete values without overwriting pending values', function (): void {
    $files = new TranslationFileRepository(new TranslationFileWriter);

    foreach (['en', 'fr'] as $locale) {
        $files->saveGroup(
            $locale,
            'messages',
            [
                'greeting' => 'Old greeting',
                'pending' => 'Existing pending value',
            ],
            [],
            ['greeting' => ['Translator note']]
        );
        $files->saveJson($locale, ['Publish example' => 'Old JSON']);
    }

    seedPublishTranslations();

    $this->from('/vox/publish')
        ->post('/vox/publish')
        ->assertRedirect('/vox/publish')
        ->assertInertiaFlash('success', 'Published 5 translation values across 4 files.');

    $english = require $this->publishLangPath.'/en/messages.php';
    $french = require $this->publishLangPath.'/fr/messages.php';
    $englishJson = json_decode(File::get($this->publishLangPath.'/en.json'), true);

    expect($english['greeting'])->toBe('Published greeting')
        ->and($french['greeting'])->toBe('Salutation publiée')
        ->and($english['pending'])->toBe('Existing pending value')
        ->and($english['incomplete'])->toBe('Complete')
        ->and($french)->not->toHaveKey('incomplete')
        ->and($englishJson['Publish example'])->toBe('Published JSON')
        ->and(File::get($this->publishLangPath.'/en/messages.php'))->toContain('// Translator note')
        ->and(VoxAudit::query()->where('action', 'publish')->exists())->toBeTrue();
});

it('rejects executable translation files before publishing any changes', function (): void {
    $files = new TranslationFileRepository(new TranslationFileWriter);
    $files->saveGroup('fr', 'messages', ['greeting' => 'Garder cette valeur']);
    File::ensureDirectoryExists($this->publishLangPath.'/en');
    File::put(
        $this->publishLangPath.'/en/messages.php',
        "<?php return ['greeting' => strtoupper('unsafe')];"
    );

    VoxTranslation::factory()
        ->approved()
        ->withValues(['en' => 'Safe English', 'fr' => 'Français sûr'])
        ->create(['group' => 'messages', 'key' => 'greeting']);

    expect(fn () => app(TranslationPublisher::class)->publish())
        ->toThrow(RuntimeException::class, 'contains executable PHP');

    expect(require $this->publishLangPath.'/fr/messages.php')
        ->toBe(['greeting' => 'Garder cette valeur']);
});

it('refreshes runtime frontend artifacts after publishing language files', function (): void {
    $runtimePath = base_path('tests/.tmp/publish-runtime-'.Str::uuid());
    File::makeDirectory($runtimePath, 0755, true);
    config()->set('vox.frontend.runtime.enabled', true);
    config()->set('vox.frontend.runtime.path', $runtimePath);
    config()->set('vox.frontend.groups.mode', 'configured');
    config()->set('vox.frontend.groups.values', ['messages']);

    try {
        $files = app(TranslationFileRepository::class);
        $files->saveGroup('en', 'messages', ['greeting' => 'Old greeting']);
        $files->saveGroup('fr', 'messages', ['greeting' => 'Ancienne salutation']);

        VoxTranslation::factory()
            ->approved()
            ->withValues(['en' => 'Published greeting', 'fr' => 'Salutation publiée'])
            ->create(['group' => 'messages', 'key' => 'greeting']);

        $result = app(TranslationPublisher::class)->publish();
        $english = json_decode(File::get($runtimePath.'/en.json'), true);

        expect($result->frontendFileCount())->toBe(2)
            ->and($english['messages.greeting'])->toBe('Published greeting');
    } finally {
        File::deleteDirectory($runtimePath);
    }
});
