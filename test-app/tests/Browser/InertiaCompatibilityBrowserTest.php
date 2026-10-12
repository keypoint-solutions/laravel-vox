<?php

use App\Models\User;
use Composer\InstalledVersions;
use KeypointSolutions\LaravelVox\Database\Factories\VoxTranslationFactory;

it('boots and navigates with the installed adapter without changing host configuration', function (): void {
    config()->set('inertia.use_script_element_for_initial_page', false);
    config()->set('vox.translate.locales.mode', 'configured');
    config()->set('vox.translate.locales.values', ['en', 'fr']);
    config()->set('vox.translate.base_locale', 'en');

    $this->actingAs(User::factory()->create(['email' => 'admin@keypoint.ro']));

    VoxTranslationFactory::new()
        ->withValues(['en' => 'Compatibility greeting', 'fr' => 'Bonjour compatible'])
        ->create(['group' => 'browser', 'key' => 'inertia-compatibility']);

    $page = visit('/vox/manage')
        ->assertSee('browser.inertia-compatibility')
        ->assertNoJavaScriptErrors();

    $usesScript = $page->script(
        '() => document.querySelector(\'script[data-page="app"][type="application/json"]\') !== null'
    );

    $adapterVersion = InstalledVersions::getVersion('inertiajs/inertia-laravel');

    expect($usesScript)->toBe(version_compare($adapterVersion, '3.0.0', '>='));

    $page->click('aside a[href="'.route('vox.dashboard', absolute: false).'"]')
        ->assertSee('Dashboard')
        ->click('aside a[href$="/manage"]')
        ->assertSee('browser.inertia-compatibility')
        ->assertNoJavaScriptErrors();

    expect(config('inertia.use_script_element_for_initial_page'))->toBeFalse();
});
