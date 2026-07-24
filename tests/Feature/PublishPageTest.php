<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use KeypointSolutions\LaravelVox\Models\VoxAudit;
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
            ->where('stats.pending', 1)
            ->where('stats.incomplete', 1)
        );
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
        ->assertInertiaFlash('success', 'Published 4 translation values across 4 files.');

    $english = require $this->publishLangPath.'/en/messages.php';
    $french = require $this->publishLangPath.'/fr/messages.php';
    $englishJson = json_decode(File::get($this->publishLangPath.'/en.json'), true);

    expect($english['greeting'])->toBe('Published greeting')
        ->and($french['greeting'])->toBe('Salutation publiée')
        ->and($english['pending'])->toBe('Existing pending value')
        ->and($english)->not->toHaveKey('incomplete')
        ->and($englishJson['Publish example'])->toBe('Published JSON')
        ->and(File::get($this->publishLangPath.'/en/messages.php'))->toContain('// Translator note')
        ->and(VoxAudit::query()->where('action', 'publish')->exists())->toBeTrue();
});
