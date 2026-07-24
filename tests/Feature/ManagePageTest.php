<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

    expect($translations->keys()->sort()->values()->all())->toBe([
        'Welcome JSON',
        'backend.dashboard',
        'frontend.welcome',
    ])->and($translations['frontend.welcome']['virtual_status'])->toBe('new')
        ->and($translations['backend.dashboard']['virtual_status'])->toBe('updated')
        ->and($translations['frontend.welcome']['is_frontend'])->toBeTrue()
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
