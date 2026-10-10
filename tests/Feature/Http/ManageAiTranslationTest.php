<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use KeypointSolutions\LaravelVox\Models\VoxAudit;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Tests\Support\LocaleProvisionTranslationDriver;

beforeEach(function (): void {
    $this->useVoxDashboard();
    config()->set('vox.translate.locales.mode', 'configured');
    config()->set('vox.translate.locales.values', ['en', 'fr']);
    config()->set('vox.translate.base_locale', 'en');
});

it('bulk translates only missing target values and returns affected translations to review', function (): void {
    $this->withoutVoxCsrfMiddleware();

    config()->set('vox.translate.driver', 'openai');
    config()->set('vox.translate.providers.openai.api_key', 'test-key');
    config()->set('vox.translate.model', 'gpt-5.4-mini');

    Http::fakeSequence()
        ->push([
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => 'Bonjour __LARAVEL_PLACEHOLDER_0__.',
                ]],
            ]],
        ])
        ->push([
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => 'Au revoir',
                ]],
            ]],
        ]);

    $missing = VoxTranslation::factory()
        ->approved()
        ->withValues(['en' => 'Hello :name.'])
        ->create(['group' => 'messages', 'key' => 'hello']);
    $flagged = VoxTranslation::factory()
        ->approved()
        ->withValues(['en' => 'Goodbye', 'fr' => '🚩Goodbye'])
        ->create(['group' => 'messages', 'key' => 'goodbye']);
    $complete = VoxTranslation::factory()
        ->approved()
        ->withValues(['en' => 'Ready', 'fr' => 'Prêt'])
        ->create(['group' => 'messages', 'key' => 'ready']);
    $blank = VoxTranslation::factory()
        ->approved()
        ->withValues(['en' => 'Optional note', 'fr' => ''])
        ->create(['group' => 'messages', 'key' => 'note']);
    $orphan = VoxTranslation::factory()
        ->orphan()
        ->approved()
        ->withValues(['en' => 'Old', 'fr' => ''])
        ->create(['group' => 'messages', 'key' => 'old']);

    $this->from('/vox/manage')
        ->post('/vox/manage/translations/bulk-translate', [
            'ids' => [$missing->id, $flagged->id, $complete->id, $blank->id, $orphan->id],
        ])
        ->assertRedirect('/vox/manage')
        ->assertInertiaFlash('success', 'AI translated 2 missing values across 2 translations.');

    expect($missing->values()->where('locale', 'fr')->value('value'))->toBe('Bonjour :name.')
        ->and($missing->fresh()->status)->toBe('pending')
        ->and($flagged->values()->where('locale', 'fr')->value('value'))->toBe('Au revoir')
        ->and($flagged->fresh()->status)->toBe('pending')
        ->and($complete->values()->where('locale', 'fr')->value('value'))->toBe('Prêt')
        ->and($complete->fresh()->status)->toBe('approved')
        ->and($blank->values()->where('locale', 'fr')->value('value'))->toBe('')
        ->and($blank->fresh()->status)->toBe('approved')
        ->and($orphan->values()->where('locale', 'fr')->value('value'))->toBe('')
        ->and($orphan->fresh()->status)->toBe('approved')
        ->and(VoxAudit::query()
            ->where('action', 'translations-bulk-translated')
            ->where('context->translations', 2)
            ->where('context->values', 2)
            ->exists())->toBeTrue();

    Http::assertSentCount(2);
});

it('keeps completed bulk translations as drafts and reports why it stopped when the AI provider fails', function (): void {
    $this->withoutVoxCsrfMiddleware();

    config()->set('vox.translate.driver', 'openai');
    config()->set('vox.translate.providers.openai.api_key', 'test-key');

    Http::fakeSequence()
        ->push([
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => 'Premier',
                ]],
            ]],
        ])
        ->push(['error' => ['message' => 'You exceeded your current quota.', 'type' => 'insufficient_quota']], 429);

    $first = VoxTranslation::factory()->approved()->withValues(['en' => 'First'])->create(['group' => 'messages', 'key' => 'a']);
    $second = VoxTranslation::factory()->approved()->withValues(['en' => 'Second'])->create(['group' => 'messages', 'key' => 'b']);
    $third = VoxTranslation::factory()->approved()->withValues(['en' => 'Third'])->create(['group' => 'messages', 'key' => 'c']);

    $this->from('/vox/manage')
        ->post('/vox/manage/translations/bulk-translate', ['ids' => [$first->id, $second->id, $third->id]])
        ->assertRedirect('/vox/manage')
        ->assertSessionHasErrors('translate');

    expect(session('errors')->first('translate'))->toBe(
        'AI translated 1 missing value across 1 translation and saved them as drafts, then stopped: '
        .'OpenAI request failed (HTTP 429): You exceeded your current quota.'
    );

    $saved = $first->values()->where('locale', 'fr')->sole();

    expect($saved->value)->toBe('Premier')
        ->and($saved->is_approved)->toBeFalse()
        ->and($first->fresh()->status)->toBe('pending')
        ->and($second->values()->where('locale', 'fr')->exists())->toBeFalse()
        ->and($third->values()->where('locale', 'fr')->exists())->toBeFalse()
        ->and($second->fresh()->status)->toBe('approved')
        ->and(VoxAudit::query()->where('action', 'translations-bulk-translated')->where('context->values', 1)->exists())->toBeTrue();

    Http::assertSentCount(2);
});

it('saves nothing and reports the provider message when the first bulk translation fails', function (): void {
    $this->withoutVoxCsrfMiddleware();
    config()->set('vox.translate.driver', 'openai');
    config()->set('vox.translate.providers.openai.api_key', 'test-key');
    Http::fake(['*' => Http::response(['error' => ['message' => 'You exceeded your current quota.']], 429)]);

    $translation = VoxTranslation::factory()->approved()->withValues(['en' => 'First'])->create();

    $this->from('/vox/manage')
        ->post('/vox/manage/translations/bulk-translate', ['ids' => [$translation->id]])
        ->assertSessionHasErrors('translate');

    expect(session('errors')->first('translate'))->toBe('OpenAI request failed (HTTP 429): You exceeded your current quota.')
        ->and($translation->values()->where('locale', 'fr')->exists())->toBeFalse()
        ->and(VoxAudit::query()->where('action', 'translations-bulk-translated')->exists())->toBeFalse();
});

it('protects Laravel placeholders when translating from the management UI', function (): void {
    $this->withoutVoxCsrfMiddleware();

    config()->set('vox.translate.driver', 'openai');
    config()->set('vox.translate.providers.openai.api_key', 'test-key');
    config()->set('vox.translate.model', 'gpt-5.4-mini');

    Http::fake([
        '*' => Http::response([
            'output' => [
                [
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'Bonjour __LARAVEL_PLACEHOLDER_0__.',
                    ]],
                ],
            ],
        ]),
    ]);

    $translation = VoxTranslation::factory()
        ->withValues(['en' => 'Hello :name.', 'fr' => ''])
        ->create(['group' => 'messages', 'key' => 'greeting']);

    $this->from('/vox/manage')
        ->post("/vox/manage/translations/{$translation->id}/translate", [
            'locales' => ['fr'],
            'base_value' => 'Hello :name.',
        ])
        ->assertRedirect('/vox/manage')
        ->assertInertiaFlash('translated_values.fr', 'Bonjour :name.');

    Http::assertSent(fn (Request $request): bool => $request['input']
        === 'Hello __LARAVEL_PLACEHOLDER_0__.');
});

it('AI translates draft dynamic values before creating the translation', function (): void {
    $this->withoutVoxCsrfMiddleware();

    config()->set('vox.translate.driver', LocaleProvisionTranslationDriver::class);

    $this->from('/vox/manage')
        ->post('/vox/manage/translations/translate-draft', [
            'locales' => ['fr'],
            'base_value' => 'Administrator',
            'key' => 'enums.user_roles.admin',
        ])
        ->assertRedirect('/vox/manage')
        ->assertInertiaFlash('translated_values.fr', 'fr: Administrator');

    expect(VoxTranslation::query()->count())->toBe(0);
});

it('skips unusable sources in bulk AI translation using the shared eligibility rules', function (): void {
    $this->withoutVoxCsrfMiddleware();
    config()->set('vox.translate.driver', 'openai');
    config()->set('vox.translate.providers.openai.api_key', 'test-key');
    config()->set('vox.parse.missing_translation_prefix', 'TODO:');
    Http::fake();
    $ids = [];
    foreach (['empty' => '', 'flagged' => 'TODO:source', 'snake_key' => 'snake_key'] as $key => $source) {
        $ids[] = VoxTranslation::factory()->withValues(['en' => $source, 'fr' => ''])
            ->create(['group' => 'messages', 'key' => $key])->id;
    }
    $this->from('/vox/manage')->post('/vox/manage/translations/bulk-translate', ['ids' => $ids])
        ->assertRedirect('/vox/manage')
        ->assertInertiaFlash('success', 'No missing target values were found in the selected translations.');
    Http::assertNothingSent();
});
