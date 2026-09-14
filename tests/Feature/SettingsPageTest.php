<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use KeypointSolutions\LaravelVox\Models\VoxSetting;

beforeEach(function (): void {
    $this->withoutVite();
    $this->withoutMiddleware(PreventRequestForgery::class);
    app()->detectEnvironment(fn () => 'local');
    config()->set('vox.system.bypass_auth_in_local', true);
    config()->set('vox.translate.providers.openai.api_key', 'test-key');
    config()->set('vox.translate.model', 'gpt-5.4-mini');

    Http::fake([
        'https://api.openai.com/v1/models' => Http::response([
            'data' => [
                ['id' => 'gpt-5.6-luna'],
                ['id' => 'gpt-5.6-terra'],
                ['id' => 'gpt-5.4-mini'],
                ['id' => 'gpt-5.4-nano'],
                ['id' => 'gpt-3.5-turbo'],
                ['id' => 'gpt-image-1'],
                ['id' => 'text-embedding-3-small'],
            ],
        ]),
    ]);
});

it('shows environment-owned credentials as status and only supported available models', function (): void {
    $this->get('/vox/settings')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings', false)
            ->missing('settings.provider_credentials')
            ->missing('settings.sync_key')
            ->where('ai.provider.id', 'openai')
            ->where('ai.provider.label', 'OpenAI')
            ->where('ai.provider.credentials_set', true)
            ->where('ai.status', 'connected')
            ->has('ai.models', 4)
            ->where('ai.models.0.value', 'gpt-5.6-luna'
            )->where('ai.models.0.label', 'GPT-5.6 Luna · Recommended')
            ->where('ai.models.1.value', 'gpt-5.4-mini')
            ->where('ai.models.2.value', 'gpt-5.4-nano')
            ->where('ai.models.3.value', 'gpt-5.6-terra')
        );
});

it('stores AI preferences in the Vox database', function (): void {
    $this->from('/vox/settings')
        ->post('/vox/settings', [
            'section' => 'ai',
            'model' => 'gpt-5.6-terra',
            'translate_guidance' => 'Use formal French.',
        ])
        ->assertRedirect('/vox/settings')
        ->assertInertiaFlash('success', 'AI translation settings saved.');

    expect(json_decode(VoxSetting::query()->findOrFail('translate_model')->value, true))
        ->toBe('gpt-5.6-terra')
        ->and(json_decode(VoxSetting::query()->findOrFail('translate_guidance')->value, true))
        ->toBe('Use formal French.');
});

it('stores the remote sync toggle without exposing its key', function (): void {
    config()->set('vox.sync.key', 'secret-value');

    $this->from('/vox/settings')
        ->post('/vox/settings', [
            'section' => 'sync',
            'sync_enabled' => false,
        ])
        ->assertRedirect('/vox/settings')
        ->assertInertiaFlash('success', 'Remote sync settings saved.');

    expect(json_decode(VoxSetting::query()->findOrFail('sync_enabled')->value, true))->toBeFalse();

    $this->get('/vox/settings')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('settings.sync_key_set', true)
            ->missing('settings.sync_key')
        );
});

it('stores UI dynamic patterns separately from configured patterns', function (): void {
    config()->set('vox.retained_keys', ['validation.*']);

    $this->from('/vox/settings')
        ->post('/vox/settings', [
            'section' => 'dynamic_keys',
            'dynamic_key_patterns' => "enums.user_roles.*\nmessages.legal.*\nenums.user_roles.*\n",
        ])
        ->assertRedirect('/vox/settings')
        ->assertInertiaFlash('success', 'Dynamic key patterns saved.');

    expect(json_decode(VoxSetting::query()->findOrFail('dynamic_key_patterns')->value, true))
        ->toBe(['enums.user_roles.*', 'messages.legal.*']);

    $this->get('/vox/settings')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('settings.dynamic_key_patterns', ['enums.user_roles.*', 'messages.legal.*'])
            ->where('settings.configured_dynamic_key_patterns', ['validation.*'])
            ->has('dynamicPatterns', 3)
        );
});

it('requires the dedicated settings ability outside local development', function (): void {
    app()->detectEnvironment(fn () => 'production');
    config()->set('vox.system.bypass_auth_in_local', false);

    Gate::define('viewVox', fn (): bool => true);
    Gate::define('manageVoxSettings', fn (): bool => false);

    $this->actingAs(new class extends User {});

    $this->get('/vox/manage')->assertOk();
    $this->get('/vox/settings')->assertForbidden();
});

it('refreshes models and reports a verified connection', function (): void {
    $this->from('/vox/settings')
        ->post('/vox/settings/ai/models')
        ->assertRedirect('/vox/settings')
        ->assertInertiaFlash('success', 'AI provider connection verified and available models refreshed.');

    Http::assertSentCount(1);
});
