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
use KeypointSolutions\LaravelVox\Tests\Support\LocaleProvisionTranslationDriver;
use KeypointSolutions\LaravelVox\Translation\TranslationFileRepository;

beforeEach(function (): void {
    $this->withoutVite();
    app()->detectEnvironment(fn () => 'local');
    config()->set('vox.system.bypass_auth_in_local', true);
    config()->set('vox.translate.locales.mode', 'configured');
    config()->set('vox.translate.locales.values', ['en', 'fr']);
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
    config()->set('vox.frontend.groups.mode', 'configured');
    config()->set('vox.frontend.groups.values', ['frontend']);
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
    ])->and($translations['frontend.welcome']['freshness_status'])->toBe('new')
        ->and($translations['backend.dashboard']['freshness_status'])->toBe('updated')
        ->and($translations['frontend.welcome']['is_frontend'])->toBeTrue()
        ->and($response->inertiaPage()['props']['groups'][0]['is_frontend_exported'])->toBeFalse()
        ->and(collect($response->inertiaPage()['props']['groups'])->firstWhere('name', 'frontend'))
        ->toMatchArray([
            'is_frontend_exported' => true,
            'frontend_export_source' => 'configured',
        ])
        ->and(collect($response->inertiaPage()['props']['groups'])->firstWhere('name', 'json'))
        ->toMatchArray([
            'is_frontend_exported' => true,
            'frontend_export_source' => 'json',
        ])
        ->and($lastSyncAt)->toMatch('/T.*(?:Z|[+-]\d{2}:\d{2})$/')
        ->and($translations['frontend.welcome']['updated_at'])->toMatch('/T.*(?:Z|[+-]\d{2}:\d{2})$/')
        ->and($translations['frontend.welcome']['occurrences'][0]['file_path'])
        ->toBe('resources/views/welcome.blade.php');
});

it('marks and filters dynamic and orphan translations separately', function (): void {
    config()->set('vox.retained_keys', ['messages.legal.*']);

    VoxTranslation::factory()
        ->withValues(['en' => 'Terms', 'fr' => 'Conditions'])
        ->create(['group' => 'messages', 'key' => 'legal.terms']);

    VoxTranslation::factory()
        ->orphan()
        ->approved()
        ->withValues(['en' => 'Old', 'fr' => 'Ancien'])
        ->create(['group' => 'messages', 'key' => 'old']);

    $all = manageTranslations($this->get('/vox/manage'))->keyBy('display_key');
    $orphans = manageTranslations($this->get('/vox/manage?status=orphan'));
    $dynamic = manageTranslations($this->get('/vox/manage?status=retained'));
    $approved = manageTranslations($this->get('/vox/manage?status=approved'));

    expect($all['messages.legal.terms']['is_retained'])->toBeTrue()
        ->and($all['messages.legal.terms']['is_dynamic'])->toBeFalse()
        ->and($all['messages.legal.terms']['dynamic_pattern'])->toBe('messages.legal.*')
        ->and($all['messages.old']['is_orphan'])->toBeTrue()
        ->and($orphans->pluck('display_key')->all())->toBe(['messages.old'])
        ->and($dynamic->pluck('display_key')->all())->toBe(['messages.legal.terms'])
        ->and($approved)->toBeEmpty();
});

it('creates concrete values covered by a dynamic pattern', function (): void {
    $this->withoutMiddleware(PreventRequestForgery::class);
    config()->set('vox.retained_keys', ['enums.user_roles.*']);

    $this->from('/vox/manage')
        ->post('/vox/manage/translations', [
            'pattern' => 'enums.user_roles.*',
            'key' => 'enums.user_roles.admin',
            'values' => [
                'en' => 'Administrator',
                'fr' => '',
            ],
        ])
        ->assertRedirect('/vox/manage')
        ->assertInertiaFlash('success', 'Dynamic translation enums.user_roles.admin created.');

    $translation = VoxTranslation::query()
        ->with('values')
        ->where('group', 'enums')
        ->where('key', 'user_roles.admin')
        ->firstOrFail();

    expect($translation)
        ->status->toBe('pending')
        ->is_orphan->toBeFalse()
        ->source->toBe('dynamic')
        ->and($translation->values->pluck('value', 'locale')->all())
        ->toBe(['en' => 'Administrator', 'fr' => ''])
        ->and(VoxAudit::query()->where('action', 'dynamic-translation-created')->exists())
        ->toBeTrue();
});

it('rejects dynamic values outside the selected pattern and duplicate keys', function (): void {
    $this->withoutMiddleware(PreventRequestForgery::class);
    config()->set('vox.retained_keys', ['enums.user_roles.*']);

    $this->from('/vox/manage')
        ->post('/vox/manage/translations', [
            'pattern' => 'enums.user_roles.*',
            'key' => 'enums.permissions.edit',
            'values' => ['en' => 'Edit'],
        ])
        ->assertRedirect('/vox/manage')
        ->assertSessionHasErrors('key');

    VoxTranslation::factory()->create(['group' => 'enums', 'key' => 'user_roles.admin']);

    $this->from('/vox/manage')
        ->post('/vox/manage/translations', [
            'pattern' => 'enums.user_roles.*',
            'key' => 'enums.user_roles.admin',
            'values' => ['en' => 'Administrator'],
        ])
        ->assertRedirect('/vox/manage')
        ->assertSessionHasErrors('key');
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

it('filters translations by freshness status', function (): void {
    seedManageTranslations();

    $translations = manageTranslations($this->get('/vox/manage?status=new'));

    expect($translations->pluck('display_key')->all())->toBe(['frontend.welcome']);
});

it('filters workflow approval independently from sync freshness', function (): void {
    seedManageTranslations();

    VoxTranslation::factory()
        ->approved()
        ->withValues(['en' => 'Recently approved', 'fr' => 'Approuvé récemment'])
        ->withTimestamps(Carbon::now()->subDays(3), Carbon::now()->subHour())
        ->create(['group' => 'messages', 'key' => 'recently-approved']);

    $translations = manageTranslations($this->get('/vox/manage?status=approved'));

    expect($translations->pluck('display_key')->all())->toContain(
        'messages.recently-approved',
        'Welcome JSON',
    );
});

it('uses the configured missing translation prefix', function (): void {
    config()->set('vox.parse.missing_translation_prefix', 'MISSING:');

    VoxTranslation::factory()
        ->withValues(['en' => 'Hello', 'fr' => 'MISSING:Bonjour'])
        ->create(['group' => 'messages', 'key' => 'greeting']);

    $translations = manageTranslations($this->get('/vox/manage?status=missing'));

    expect($translations->pluck('display_key')->all())->toBe(['messages.greeting']);
});

it('toggles approval without making translation content appear freshly updated', function (): void {
    $this->withoutMiddleware(PreventRequestForgery::class);

    $contentUpdatedAt = Carbon::now()->subDays(3)->startOfSecond();
    $translation = VoxTranslation::factory()
        ->pending()
        ->withValues(['en' => 'Hello', 'fr' => 'Bonjour'])
        ->withTimestamps(Carbon::now()->subDays(4), $contentUpdatedAt)
        ->create(['group' => 'messages', 'key' => 'greeting']);

    $this->from('/vox/manage')
        ->post("/vox/manage/translations/{$translation->id}/toggle-approval")
        ->assertRedirect('/vox/manage')
        ->assertInertiaFlash('success', 'Translation approved.');

    $translation->refresh();

    expect($translation->status)->toBe('approved')
        ->and($translation->updated_at?->equalTo($contentUpdatedAt))->toBeTrue()
        ->and(VoxAudit::query()
            ->where('action', 'translation-approved')
            ->where('context->translation_id', $translation->id)
            ->exists())->toBeTrue();
});

it('bulk approves translations without changing their content timestamps', function (): void {
    $this->withoutMiddleware(PreventRequestForgery::class);

    $contentUpdatedAt = Carbon::now()->subDays(3)->startOfSecond();
    $translations = VoxTranslation::factory()
        ->count(2)
        ->pending()
        ->withTimestamps(Carbon::now()->subDays(4), $contentUpdatedAt)
        ->create();

    $this->from('/vox/manage')
        ->post('/vox/manage/translations/bulk-approval', [
            'ids' => $translations->pluck('id')->all(),
            'status' => 'approved',
        ])
        ->assertRedirect('/vox/manage')
        ->assertInertiaFlash('success', 'Approved 2 translations.');

    $translations->each->refresh();

    expect($translations->pluck('status')->unique()->all())->toBe(['approved'])
        ->and($translations->every(
            fn (VoxTranslation $translation): bool => $translation->updated_at?->equalTo($contentUpdatedAt) === true
        ))->toBeTrue()
        ->and(VoxAudit::query()
            ->where('action', 'translations-bulk-approved')
            ->where('context->count', 2)
            ->exists())->toBeTrue();
});

it('bulk translates only missing target values and returns affected translations to review', function (): void {
    $this->withoutMiddleware(PreventRequestForgery::class);

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
        ->withValues(['en' => 'Hello :name.', 'fr' => ''])
        ->create(['group' => 'messages', 'key' => 'hello']);
    $flagged = VoxTranslation::factory()
        ->approved()
        ->withValues(['en' => 'Goodbye', 'fr' => '🚩Goodbye'])
        ->create(['group' => 'messages', 'key' => 'goodbye']);
    $complete = VoxTranslation::factory()
        ->approved()
        ->withValues(['en' => 'Ready', 'fr' => 'Prêt'])
        ->create(['group' => 'messages', 'key' => 'ready']);
    $orphan = VoxTranslation::factory()
        ->orphan()
        ->approved()
        ->withValues(['en' => 'Old', 'fr' => ''])
        ->create(['group' => 'messages', 'key' => 'old']);

    $this->from('/vox/manage')
        ->post('/vox/manage/translations/bulk-translate', [
            'ids' => [$missing->id, $flagged->id, $complete->id, $orphan->id],
        ])
        ->assertRedirect('/vox/manage')
        ->assertInertiaFlash('success', 'AI translated 2 missing values across 2 translations.');

    expect($missing->values()->where('locale', 'fr')->value('value'))->toBe('Bonjour :name.')
        ->and($missing->fresh()->status)->toBe('pending')
        ->and($flagged->values()->where('locale', 'fr')->value('value'))->toBe('Au revoir')
        ->and($flagged->fresh()->status)->toBe('pending')
        ->and($complete->values()->where('locale', 'fr')->value('value'))->toBe('Prêt')
        ->and($complete->fresh()->status)->toBe('approved')
        ->and($orphan->values()->where('locale', 'fr')->value('value'))->toBe('')
        ->and($orphan->fresh()->status)->toBe('approved')
        ->and(VoxAudit::query()
            ->where('action', 'translations-bulk-translated')
            ->where('context->translations', 2)
            ->where('context->values', 2)
            ->exists())->toBeTrue();

    Http::assertSentCount(2);
});

it('does not persist partial bulk translations when the AI provider fails', function (): void {
    $this->withoutMiddleware(PreventRequestForgery::class);

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
        ->push(['output' => []]);

    $translations = VoxTranslation::factory()
        ->count(2)
        ->approved()
        ->withValues(['en' => 'Source', 'fr' => ''])
        ->create();

    $this->from('/vox/manage')
        ->post('/vox/manage/translations/bulk-translate', [
            'ids' => $translations->pluck('id')->all(),
        ])
        ->assertRedirect('/vox/manage')
        ->assertSessionHasErrors('translate');

    expect($translations->every(
        fn (VoxTranslation $translation): bool => $translation
            ->values()
            ->where('locale', 'fr')
            ->value('value') === ''
    ))->toBeTrue()
        ->and($translations->every(
            fn (VoxTranslation $translation): bool => $translation->fresh()->status === 'approved'
        ))->toBeTrue()
        ->and(VoxAudit::query()->where('action', 'translations-bulk-translated')->exists())->toBeFalse();
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

it('AI translates draft dynamic values before creating the translation', function (): void {
    $this->withoutMiddleware(PreventRequestForgery::class);

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
    expect(VoxAudit::query()
        ->where('action', 'translation-updated')
        ->where('context->translation_id', $translation->id)
        ->exists())->toBeTrue();
});

it('uses the recorded sync start as the new and updated boundary', function (): void {
    $startedAt = Carbon::now()->subMinutes(10);
    $completedAt = Carbon::now();
    $translation = VoxTranslation::factory()
        ->withValues(['en' => 'Changed during sync', 'fr' => 'Modifié pendant la synchronisation'])
        ->withTimestamps(Carbon::now()->subDay(), Carbon::now()->subMinutes(5))
        ->create(['group' => 'messages', 'key' => 'sync-change']);

    VoxAudit::factory()->sync(1)->create([
        'context' => [
            'translations' => 1,
            'started_at' => $startedAt->toIso8601String(),
        ],
        'created_at' => $completedAt,
    ]);

    $translations = manageTranslations($this->get('/vox/manage?status=updated'));

    expect($translations->pluck('id')->all())->toContain($translation->id);
});

it('allows published dynamic keys without direct source usage to be deleted', function (): void {
    prepareVoxFixtures();
    VoxTranslation::factory()->create(['group' => 'messages', 'key' => 'welcome', 'source' => 'dynamic']);
    $files = app(TranslationFileRepository::class);
    $values = $files->loadGroup('en', 'messages');
    $key = 'unused_dynamic';
    $files->saveGroup('en', 'messages', [$key => 'Unused']);
    config()->set('vox.retained_keys', ['messages.*']);
    VoxTranslation::query()->update(['key' => $key]);

    $this->get('/vox/manage')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('translations.data.0.deletion_unavailable_reason', null));
});

it('counts every group by status independently of the selected group and search', function (?string $status): void {
    config()->set('vox.parse.missing_translation_prefix', 'TODO:');

    VoxTranslation::factory()->orphan()->withValues(['en' => 'One', 'fr' => 'Un'])->create(['group' => 'json']);
    VoxTranslation::factory()->orphan()->withValues(['en' => 'Two', 'fr' => 'Deux'])->create(['group' => null]);
    VoxTranslation::factory()->approved()->withValues(['en' => 'Three', 'fr' => 'Trois'])->create(['group' => 'json']);
    VoxTranslation::factory()->withValues(['en' => 'Four', 'fr' => 'TODO:Four'])->create(['group' => 'messages']);
    VoxTranslation::factory()->withValues(['en' => 'Five', 'fr' => 'Cinq'])->create(['group' => 'ignored', 'is_ignored' => true]);
    VoxTranslation::factory()->withValues(['en' => 'Six', 'fr' => 'Six'])->create(['group' => 'deleted', 'is_pending_delete' => true]);

    $response = $this->get('/vox/manage?'.http_build_query(['status' => $status]));
    $response->assertOk();
    $props = $response->inertiaPage()['props'];
    $counts = collect($props['groups'])->pluck('total', 'name');
    $expected = collect($props['translations']['data'])->countBy(fn (array $translation): string => $translation['group'] ?? 'default');

    expect($counts)->toHaveCount(5)
        ->and($counts->sum())->toBe($props['translations']['total']);

    foreach ($counts as $group => $count) {
        expect($count)->toBe($expected->get($group, 0));
    }

    $filtered = $this->get('/vox/manage?'.http_build_query([
        'status' => $status,
        'group' => 'json',
        'scope' => 'group',
        'search' => 'no matching key',
    ]));
    $filtered->assertOk();

    expect($filtered->inertiaPage()['props']['groups'])->toBe($props['groups'])
        ->and($filtered->inertiaPage()['props']['translations']['total'])->toBe(0);
})->with([null, 'orphan', 'missing', 'approved', 'pending', 'ignored', 'pending-deletion']);
