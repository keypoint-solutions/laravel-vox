<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Translation\Files\TranslationFileRepository;
use KeypointSolutions\LaravelVox\Translation\Files\TranslationFileWriter;
use KeypointSolutions\LaravelVox\Translation\Publishing\TranslationPublisher;
use KeypointSolutions\LaravelVox\Translation\TranslationFallbackRules;

beforeEach(function (): void {
    $this->useVoxDashboard();
    $this->withoutVoxCsrfMiddleware();
    config()->set('vox.translate.locales.mode', 'configured');
    config()->set('vox.translate.locales.values', ['en', 'fr']);
    config()->set('vox.translate.base_locale', 'en');
    config()->set('vox.parse.missing_translation_prefix', '🚩');

    $this->blankLangPath = base_path('tests/.tmp/blank-'.Str::uuid());
    File::makeDirectory($this->blankLangPath, 0755, true);
    config()->set('vox.paths.lang', $this->blankLangPath);
});

afterEach(function (): void {
    File::deleteDirectory($this->blankLangPath);
});

it('saves a cleared value as a blank draft and leaves never-filled values missing', function (): void {
    $translation = VoxTranslation::factory()
        ->approved()
        ->withValues(['en' => 'Hello', 'fr' => 'Bonjour'])
        ->create(['group' => 'messages', 'key' => 'greeting']);
    $untouched = VoxTranslation::factory()
        ->withValues(['en' => 'Bye'])
        ->create(['group' => 'messages', 'key' => 'farewell']);

    $this->from('/vox/manage')
        ->patch("/vox/manage/translations/{$translation->id}", ['values' => ['en' => 'Hello', 'fr' => '']])
        ->assertRedirect('/vox/manage');
    $this->from('/vox/manage')
        ->patch("/vox/manage/translations/{$untouched->id}", ['values' => ['en' => 'Bye', 'fr' => '']])
        ->assertRedirect('/vox/manage');

    $cleared = $translation->values()->where('locale', 'fr')->sole();

    expect($cleared->value)->toBe('')
        ->and($cleared->is_approved)->toBeFalse()
        ->and($cleared->is_pending_publish)->toBeTrue()
        ->and($untouched->values()->where('locale', 'fr')->exists())->toBeFalse();
});

it('does not report a blank target as missing', function (): void {
    VoxTranslation::factory()
        ->withValues(['en' => 'Optional note', 'fr' => ''])
        ->create(['group' => 'messages', 'key' => 'note']);
    VoxTranslation::factory()
        ->withValues(['en' => 'Title'])
        ->create(['group' => 'messages', 'key' => 'title']);

    $this->get('/vox/manage?status=missing')->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('translations.data', fn ($rows): bool => collect($rows)->pluck('display_key')->all() === ['messages.title']));

    $this->get('/vox/manage')->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('translations.data', fn ($rows): bool => collect($rows)->pluck('has_missing_values', 'display_key')->all() === [
            'messages.note' => false,
            'messages.title' => true,
        ] || collect($rows)->pluck('has_missing_values', 'display_key')->all() === [
            'messages.title' => true,
            'messages.note' => false,
        ]));
});

it('publishes an approved blank value and holds back a blank draft', function (): void {
    $files = new TranslationFileRepository(new TranslationFileWriter);
    $files->saveGroup('en', 'messages', ['approved' => 'Approved', 'draft' => 'Draft']);
    $files->saveGroup('fr', 'messages', ['approved' => 'Approuvé', 'draft' => 'Brouillon']);
    $this->artisan('vox:sync', ['--no-interaction' => true])->assertSuccessful();

    $approved = VoxTranslation::query()->where('key', 'approved')->sole()->values()->where('locale', 'fr')->sole();
    $approved->saveDraft('', true);
    VoxTranslation::query()->where('key', 'draft')->sole()->values()->where('locale', 'fr')->sole()->saveDraft('');

    $this->get('/vox/publish')->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('stats.publishable_values', 1)
        ->where('stats.incomplete', 0));

    app(TranslationPublisher::class)->publish();

    expect($files->loadGroup('fr', 'messages'))->toBe(['approved' => '', 'draft' => 'Brouillon']);
});

it('publishes a blank default to locales that follow the default wording', function (): void {
    $files = new TranslationFileRepository(new TranslationFileWriter);
    $translation = VoxTranslation::factory()->approved()->create(['group' => 'messages', 'key' => 'note']);

    foreach (['en' => 'Note', 'fr' => 'Remarque'] as $locale => $value) {
        $translation->values()->create(['locale' => $locale, 'value' => $value, 'file_value' => $value, 'is_approved' => true, 'is_pending_publish' => false]);
        $files->saveGroup($locale, 'messages', ['note' => $value]);
    }

    $translation->values()->where('locale', 'en')->sole()->saveDraft('', true);
    app(TranslationFallbackRules::class)->save('fr', 'locale', 'default');
    app(TranslationPublisher::class)->publish();

    expect($files->loadGroup('en', 'messages')['note'])->toBe('')
        ->and($files->loadGroup('fr', 'messages')['note'])->toBe('');
});

it('refuses AI actions with the same reason whenever no provider is configured', function (string $driver): void {
    config()->set('vox.translate.driver', $driver);
    config()->set('vox.translate.providers.openai.api_key', null);
    Http::fake();
    $translation = VoxTranslation::factory()
        ->withValues(['en' => 'Hello'])
        ->create(['group' => 'messages', 'key' => 'greeting']);

    $requests = [
        ["/vox/manage/translations/{$translation->id}/translate", ['locales' => ['fr'], 'base_value' => 'Hello']],
        ['/vox/manage/translations/translate-draft', ['locales' => ['fr'], 'base_value' => 'Hello', 'key' => 'messages.new']],
        ['/vox/manage/translations/bulk-translate', ['ids' => [$translation->id]]],
    ];

    foreach ($requests as [$url, $payload]) {
        $this->from('/vox/manage')->post($url, $payload)
            ->assertRedirect('/vox/manage')
            ->assertSessionHasErrors(['translate']);
        expect(session('errors')->first('translate'))->toStartWith('AI translation is not configured.');
    }

    $this->from('/vox/sync')
        ->post('/vox/sync/locales', ['locale' => 'de', 'auto_translate' => true])
        ->assertSessionHasErrors('auto_translate');

    $this->get('/vox/manage')->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('ai', ['available' => false]));
    $this->get('/vox/sync')->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('ai.available', false)
        ->where('ai.can_choose', false));

    Http::assertNothingSent();
})->with(['null', 'openai']);

it('stops vox:translate before touching files when no provider is configured', function (string $driver): void {
    config()->set('vox.translate.driver', $driver);
    config()->set('vox.translate.providers.openai.api_key', null);
    $files = new TranslationFileRepository(new TranslationFileWriter);
    $files->saveGroup('en', 'messages', ['greeting' => 'Hello']);
    $files->saveGroup('fr', 'messages', ['greeting' => '🚩Hello']);
    $before = File::get($files->groupPath('fr', 'messages'));

    $this->artisan('vox:translate')
        ->expectsOutputToContain('AI translation is not configured.')
        ->assertFailed();

    expect(File::get($files->groupPath('fr', 'messages')))->toBe($before);
})->with(['null', 'openai']);

it('tells the editor which keys cannot serve as their own source wording', function (): void {
    VoxTranslation::factory()->withValues(['en' => 'snake_key'])->create(['group' => 'messages', 'key' => 'snake_key']);
    VoxTranslation::factory()->withValues(['en' => 'Hello'])->create(['group' => 'messages', 'key' => 'Hello']);

    $this->get('/vox/manage')->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('translations.data', fn ($rows): bool => collect($rows)->pluck('is_placeholder_key', 'key')->all() == [
            'snake_key' => true,
            'Hello' => false,
        ]));
});
