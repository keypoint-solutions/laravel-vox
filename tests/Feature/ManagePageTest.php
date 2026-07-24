<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use KeypointSolutions\LaravelVox\Models\VoxAudit;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;

beforeEach(function (): void {
    $this->withoutVite();
    app()->detectEnvironment(fn () => 'local');
    config()->set('vox.system.bypass_auth_in_local', true);
    config()->set('vox.translate.locales', ['en', 'fr']);
    config()->set('vox.translate.base_locale', 'en');
});

function seedManageTranslations(): void
{
    $syncAt = Carbon::now()->subDay();

    VoxAudit::factory()->sync(3)->create(['created_at' => $syncAt]);

    VoxTranslation::factory()
        ->frontend()
        ->withValues(['en' => 'Welcome', 'fr' => 'Bienvenue'])
        ->withOccurrence('resources/views/welcome.blade.php', 12, '<h1>', '</h1>')
        ->withTimestamps(Carbon::now()->subHours(2))
        ->create(['group' => 'frontend', 'key' => 'welcome']);

    VoxTranslation::factory()
        ->withValues(['en' => 'Dashboard', 'fr' => 'Tableau de bord'])
        ->withTimestamps(Carbon::now()->subDays(3), Carbon::now()->subHours(1))
        ->create(['group' => 'backend', 'key' => 'dashboard']);

    VoxTranslation::factory()
        ->json()
        ->approved()
        ->withValues(['en' => 'Welcome JSON', 'fr' => 'Bienvenue JSON'])
        ->withTimestamps(Carbon::now()->subDays(4))
        ->create(['key' => 'Welcome JSON']);
}

/**
 * @return Collection<int, array<string, mixed>>
 */
function manageTranslations(TestResponse $response): Collection
{
    $response->assertOk();

    return collect($response->inertiaPage()['props']['translations']['data']);
}

it('returns manage data with groups, statuses, and occurrences', function (): void {
    seedManageTranslations();

    $response = $this->get('/vox/manage');

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Manage', false)
            ->has('groups', 3)
            ->has('translations.data', 3)
        );

    $translations = manageTranslations($response)->keyBy('display_key');
    $lastSyncAt = $response->inertiaPage()['props']['lastSyncAt'];

    expect($translations->keys()->sort()->values()->all())->toBe([
        'Welcome JSON',
        'backend.dashboard',
        'frontend.welcome',
    ])->and($translations['frontend.welcome']['virtual_status'])->toBe('new')
        ->and($translations['backend.dashboard']['virtual_status'])->toBe('updated')
        ->and($translations['frontend.welcome']['is_frontend'])->toBeTrue()
        ->and($lastSyncAt)->toMatch('/T.*(?:Z|[+-]\d{2}:\d{2})$/')
        ->and($translations['frontend.welcome']['updated_at'])->toMatch('/T.*(?:Z|[+-]\d{2}:\d{2})$/')
        ->and($translations['frontend.welcome']['occurrences'][0]['file_path'])
        ->toBe('resources/views/welcome.blade.php');
});

it('filters translations by group scope', function (): void {
    seedManageTranslations();

    $translations = manageTranslations($this->get('/vox/manage?group=frontend&scope=group'));

    expect($translations->pluck('display_key')->all())->toBe(['frontend.welcome']);
});

it('supports global search across groups', function (): void {
    seedManageTranslations();

    $translations = manageTranslations($this->get('/vox/manage?search=dashboard&scope=all'));

    expect($translations->pluck('display_key')->all())->toBe(['backend.dashboard']);
});

it('filters translations by virtual status', function (): void {
    seedManageTranslations();

    $translations = manageTranslations($this->get('/vox/manage?status=new'));

    expect($translations->pluck('display_key')->all())->toBe(['frontend.welcome']);
});

it('protects Laravel placeholders when translating from the management UI', function (): void {
    $this->withoutMiddleware(PreventRequestForgery::class);

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

it('returns a success flash after saving translation values', function (): void {
    $this->withoutMiddleware(PreventRequestForgery::class);

    $translation = VoxTranslation::factory()
        ->withValues(['en' => 'Hello', 'fr' => 'Bonjour'])
        ->create(['group' => 'messages', 'key' => 'greeting']);

    $this->from('/vox/manage')
        ->patch("/vox/manage/translations/{$translation->id}", [
            'values' => [
                'en' => 'Hello',
                'fr' => 'Salut',
            ],
        ])
        ->assertRedirect('/vox/manage')
        ->assertInertiaFlash('success', 'Translations saved.');

    expect($translation->values()->where('locale', 'fr')->value('value'))->toBe('Salut');
});
