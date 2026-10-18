<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Support\VoxSettingsRepository;
use KeypointSolutions\LaravelVox\Tests\Support\LocaleProvisionTranslationDriver;
use KeypointSolutions\LaravelVox\Translation\Files\TranslationFileRepository;
use KeypointSolutions\LaravelVox\Translation\Files\TranslationFileWriter;
use KeypointSolutions\LaravelVox\Translation\Locales\LocaleProvisioner;

beforeEach(function (): void {
    config()->set('vox.translate.locales.mode', 'configured');
    config()->set('vox.translate.locales.values', ['en', 'fr']);
    config()->set('vox.translate.base_locale', 'en');
    config()->set('vox.parse.paths', []);

    $this->localeRoot = base_path('tests/.tmp/locale-commands-'.Str::uuid());
    File::makeDirectory($this->localeRoot.'/lang', 0755, true);
    config()->set('vox.paths.lang', $this->localeRoot.'/lang');
    config()->set('vox.frontend.manifest', $this->localeRoot.'/frontend.json');
    config()->set('vox.frontend.runtime.path', $this->localeRoot.'/runtime');

    $this->files = new TranslationFileRepository(new TranslationFileWriter);
    $this->files->saveGroup('en', 'messages', ['first' => 'First', 'second' => 'Second']);
});

afterEach(function (): void {
    File::deleteDirectory($this->localeRoot);
});

it('adds a locale with flagged values without AI translation', function (): void {
    config()->set('vox.translate.driver', LocaleProvisionTranslationDriver::class);

    $this->artisan('vox:add-locale', ['locale' => 'fr', '--no-interaction' => true])
        ->expectsOutputToContain('Added French (fr) with 2 values flagged for translation.')
        ->assertExitCode(0);

    expect($this->files->loadGroup('fr', 'messages'))->toBe(['first' => '🚩First', 'second' => '🚩Second']);
});

it('adds a locale with AI translations and warns when it is not declared', function (): void {
    config()->set('vox.translate.driver', LocaleProvisionTranslationDriver::class);

    $this->artisan('vox:add-locale', ['locale' => 'ro', '--translate' => true, '--no-interaction' => true])
        ->expectsOutputToContain('AI translated 2 of 2 values')
        ->expectsOutputToContain('Not listed in vox.translate.locales')
        ->assertExitCode(0);

    expect($this->files->loadGroup('ro', 'messages'))->toBe(['first' => 'ro: First', 'second' => 'ro: Second'])
        ->and(app(VoxSettingsRepository::class)->provisionedLocales())->toBe(['ro']);
});

it('keeps the added locale and fails when AI translation stops part-way', function (): void {
    config()->set('vox.translate.driver', 'openai');
    config()->set('vox.translate.providers.openai.api_key', 'test-key');
    Http::fakeSequence()
        ->push(['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Primul']]]]])
        ->push(['error' => ['message' => 'You exceeded your current quota.']], 429);

    $this->artisan('vox:add-locale', ['locale' => 'ro', '--translate' => true, '--no-interaction' => true])
        ->expectsOutputToContain('AI translation stopped early')
        ->expectsOutputToContain('vox:translate --locale=ro')
        ->assertExitCode(1);

    expect($this->files->loadGroup('ro', 'messages'))->toBe(['first' => 'Primul', 'second' => '🚩Second']);
});

it('rejects invalid add-locale requests without changing files', function (array $arguments, string $message): void {
    config()->set('vox.translate.driver', 'null');
    $this->files->saveGroup('fr', 'messages', ['first' => 'Premier']);

    $this->artisan('vox:add-locale', [...$arguments, '--no-interaction' => true])
        ->expectsOutputToContain($message)
        ->assertExitCode(1);

    expect(File::exists($this->localeRoot.'/lang/de'))->toBeFalse();
})->with([
    'invalid code' => [['locale' => '../de'], 'Enter a valid locale code'],
    'missing code' => [[], 'Enter a valid locale code'],
    'already defined' => [['locale' => 'fr'], 'Locale [fr] is already defined.'],
    'conflicting options' => [['locale' => 'de', '--translate' => true, '--follow-default' => true], 'Choose either --translate or --follow-default.'],
    'AI unavailable' => [['locale' => 'de', '--translate' => true], 'not configured'],
]);

it('removes a locale added through Vox', function (): void {
    app(LocaleProvisioner::class)->provision('de');

    $this->artisan('vox:remove-locale', ['locale' => 'de', '--force' => true])
        ->expectsOutputToContain('Removed German (de).')
        ->assertExitCode(0);

    expect(File::exists($this->localeRoot.'/lang/de'))->toBeFalse()
        ->and(app(VoxSettingsRepository::class)->provisionedLocales())->toBe([]);
});

it('requires confirmation or --force to remove a locale', function (): void {
    app(LocaleProvisioner::class)->provision('de');

    $this->artisan('vox:remove-locale', ['locale' => 'de', '--no-interaction' => true])
        ->expectsOutputToContain('Pass --force')
        ->assertExitCode(1);

    $this->artisan('vox:remove-locale', ['locale' => 'de'])
        ->expectsConfirmation('Permanently remove German (de), including its files, translations, and review data?', 'no')
        ->assertExitCode(1);

    expect(File::exists($this->localeRoot.'/lang/de'))->toBeTrue();
});

it('refuses to remove protected locales', function (string $locale, string $message): void {
    $this->artisan('vox:remove-locale', ['locale' => $locale, '--force' => true])
        ->expectsOutputToContain($message)
        ->assertExitCode(1);
})->with([
    'base' => ['en', 'The default and fallback languages cannot be removed.'],
    'declared' => ['fr', 'Remove this language from application configuration first.'],
    'not added through Vox' => ['de', 'Only languages added through Vox can be removed here.'],
]);
