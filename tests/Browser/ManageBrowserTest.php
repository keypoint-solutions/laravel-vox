<?php

use Illuminate\Support\Carbon;
use KeypointSolutions\LaravelVox\Models\VoxAudit;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;

beforeEach(function (): void {
    app()->detectEnvironment(fn () => 'local');
    config()->set('vox.system.bypass_auth_in_local', true);
    config()->set('vox.translate.locales', ['en', 'fr']);
    config()->set('vox.translate.base_locale', 'en');
});

function seedManageTranslations(): void
{
    $syncAt = Carbon::now()->subDay();

    VoxAudit::factory()->sync(3)->create(['created_at' => $syncAt]);

    // "New" translation: created after last sync
    VoxTranslation::factory()
        ->frontend()
        ->withValues(['en' => 'Welcome', 'fr' => 'Bienvenue'])
        ->withOccurrence('resources/views/welcome.blade.php', 12, '<h1>', '</h1>')
        ->withTimestamps(Carbon::now()->subHours(2))
        ->create(['group' => 'frontend', 'key' => 'welcome']);

    // "Updated" translation: created before sync, updated after sync
    VoxTranslation::factory()
        ->withValues(['en' => 'Dashboard', 'fr' => 'Tableau de bord'])
        ->withTimestamps(Carbon::now()->subDays(3), Carbon::now()->subHours(1))
        ->create(['group' => 'backend', 'key' => 'dashboard']);

    // "Approved" translation: created and updated before sync
    VoxTranslation::factory()
        ->json()
        ->approved()
        ->withValues(['en' => 'Welcome JSON', 'fr' => 'Bienvenue JSON'])
        ->withTimestamps(Carbon::now()->subDays(4))
        ->create(['key' => 'Welcome JSON']);
}

it('renders manage data with groups, statuses, and occurrences', function () {
    seedManageTranslations();

    $page = visit('/vox/manage');

    // Inertia HTML-encodes JSON data, so we need to use &quot; instead of "
    $page->assertSourceHas('frontend.welcome')
        ->assertSourceHas('backend.dashboard')
        ->assertSourceHas('Welcome JSON')
        ->assertSourceHas('virtual_status&quot;:&quot;new')
        ->assertSourceHas('virtual_status&quot;:&quot;updated')
        ->assertSourceHas('is_frontend&quot;:true')
        ->assertSourceHas('resources\\/views\\/welcome.blade.php');
});

it('filters translations by group scope', function () {
    seedManageTranslations();

    $page = visit('/vox/manage?group=frontend&scope=group');

    $page->assertSourceHas('frontend.welcome')
        ->assertSourceMissing('backend.dashboard')
        ->assertSourceMissing('Welcome JSON');
});

it('supports global search across groups', function () {
    seedManageTranslations();

    $page = visit('/vox/manage?search=dashboard&scope=all');

    $page->assertSourceHas('backend.dashboard')
        ->assertSourceMissing('frontend.welcome')
        ->assertSourceMissing('Welcome JSON');
});

it('filters translations by virtual status', function () {
    seedManageTranslations();

    $page = visit('/vox/manage?status=new');

    $page->assertSourceHas('frontend.welcome')
        ->assertSourceMissing('backend.dashboard')
        ->assertSourceMissing('Welcome JSON');
});
