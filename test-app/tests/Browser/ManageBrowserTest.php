<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use KeypointSolutions\LaravelVox\Database\Factories\VoxAuditFactory;
use KeypointSolutions\LaravelVox\Database\Factories\VoxTranslationFactory;

beforeEach(function (): void {
    config()->set('vox.translate.locales', ['en', 'fr']);
    config()->set('vox.translate.base_locale', 'en');
});

it('loads and filters the package management UI', function (): void {
    $this->actingAs(User::factory()->create([
        'email' => 'admin@keypoint.ro',
    ]));

    VoxAuditFactory::new()->sync(2)->create([
        'created_at' => Carbon::now()->subDay(),
    ]);

    VoxTranslationFactory::new()
        ->frontend()
        ->withValues(['en' => 'Welcome', 'fr' => 'Bienvenue'])
        ->create(['group' => 'frontend', 'key' => 'welcome']);

    VoxTranslationFactory::new()
        ->withValues(['en' => 'Dashboard', 'fr' => 'Tableau de bord'])
        ->create(['group' => 'backend', 'key' => 'dashboard']);

    $page = visit('/vox/manage')
        ->assertNoJavaScriptErrors()
        ->assertSee('Manage Translations')
        ->assertSee('frontend.welcome')
        ->assertSee('backend.dashboard');

    $page->type('input[placeholder="Search by key, group, or value..."]', 'dashboard')
        ->wait(1)
        ->assertSee('backend.dashboard')
        ->assertDontSee('frontend.welcome')
        ->assertNoJavaScriptErrors();
});
