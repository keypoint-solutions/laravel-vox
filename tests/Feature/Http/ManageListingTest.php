<?php

use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use KeypointSolutions\LaravelVox\Models\VoxAudit;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Translation\Files\TranslationFileRepository;

beforeEach(function (): void {
    $this->useVoxDashboard();
    config()->set('vox.translate.locales.mode', 'configured');
    config()->set('vox.translate.locales.values', ['en', 'fr']);
    config()->set('vox.translate.base_locale', 'en');
});

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
    VoxTranslation::factory()->withValues(['en' => 'Six', 'fr' => 'Six'])->create(['group' => 'deleted', 'is_pending_delete' => true]);

    $response = $this->get('/vox/manage?'.http_build_query(['status' => $status]));
    $response->assertOk();
    $props = $response->inertiaPage()['props'];
    $counts = collect($props['groups'])->pluck('total', 'name');
    $expected = collect($props['translations']['data'])->countBy(fn (array $translation): string => $translation['group'] ?? 'default');

    expect($counts)->toHaveCount(4)
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
})->with([null, 'orphan', 'missing', 'approved', 'pending', 'pending-deletion']);

it('separates empty default wording from missing translations and counts groups consistently', function (): void {
    config()->set('vox.translate.base_locale', 'fr');
    VoxTranslation::factory()->withValues(['en' => 'English', 'fr' => ''])->create(['group' => 'empty', 'key' => 'blank']);
    VoxTranslation::factory()->withValues(['en' => 'English'])->create(['group' => 'empty', 'key' => 'absent']);
    VoxTranslation::factory()->withValues(['fr' => 'Bonjour'])->create(['group' => 'work', 'key' => 'missing']);
    VoxTranslation::factory()->withValues(['en' => '', 'fr' => 'Bonjour'])->create(['group' => 'work', 'key' => 'blank']);
    VoxTranslation::factory()->withValues(['en' => 'Hello', 'fr' => '🚩Hello'])->create(['group' => 'work', 'key' => 'flagged']);
    VoxTranslation::factory()->withValues(['en' => 'Hello', 'fr' => 'Bonjour'])->create(['group' => 'work', 'key' => 'complete']);
    $empty = $this->get('/vox/manage?status=empty');
    expect(manageTranslations($empty)->pluck('display_key')->sort()->values()->all())->toBe(['empty.absent', 'empty.blank'])
        ->and(manageTranslations($empty)->pluck('has_missing_values')->unique()->all())->toBe([false]);
    $missing = $this->get('/vox/manage?status=missing');
    expect(manageTranslations($missing)->pluck('display_key')->sort()->values()->all())->toBe(['work.flagged', 'work.missing']);
    expect(collect($empty->inertiaPage()['props']['groups'])->sum('total'))->toBe(2)
        ->and(collect($missing->inertiaPage()['props']['groups'])->sum('total'))->toBe(2);
});

it('exposes published overrides independently of drafts and approval', function (): void {
    $published = VoxTranslation::factory()->approved()->withValues(['en' => 'Hello', 'fr' => 'Bonjour'])->create(['group' => 'messages', 'key' => 'published']);
    $published->values()->where('locale', 'fr')->update(['file_value' => 'Bonjour', 'published_override' => 'Bonjour', 'is_pending_publish' => false, 'is_approved' => true]);
    $draft = VoxTranslation::factory()->withValues(['en' => 'Draft'])->create(['group' => 'messages', 'key' => 'draft']);
    $draft->values()->where('locale', 'en')->firstOrFail()->saveDraft('Changed draft');

    $rows = manageTranslations($this->get('/vox/manage'))->keyBy('display_key');
    expect($rows['messages.published']['published_overrides']['fr'])->toBe('Bonjour')
        ->and($rows['messages.published']['draft_locales'])->toBe([])
        ->and($rows['messages.draft']['draft_locales'])->toBe(['en']);

    expect(manageTranslations($this->get('/vox/manage?status=published-overrides'))->pluck('id')->all())->toBe([$published->id])
        ->and(manageTranslations($this->get('/vox/manage?status=drafts'))->pluck('id')->all())->toBe([$draft->id]);
});
