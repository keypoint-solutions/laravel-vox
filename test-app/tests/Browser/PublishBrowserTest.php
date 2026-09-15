<?php

use App\Models\User;
use Illuminate\Support\Facades\File;
use KeypointSolutions\LaravelVox\Database\Factories\VoxTranslationFactory;

beforeEach(function (): void {
    config()->set('vox.translate.locales.mode', 'configured');
    config()->set('vox.translate.locales.values', ['en', 'fr']);
    config()->set('vox.translate.base_locale', 'en');

    $this->actingAs(User::factory()->create(['email' => 'admin@keypoint.ro']));
});

afterEach(function (): void {
    File::delete(
        lang_path('en/vox_browser_publish.php'),
        lang_path('fr/vox_browser_publish.php'),
    );
});

it('publishes complete approved values and reports success', function (): void {
    VoxTranslationFactory::new()
        ->approved()
        ->withValues([
            'en' => 'Published from the browser',
            'fr' => 'Publié depuis le navigateur',
        ])
        ->create(['group' => 'vox_browser_publish', 'key' => 'message']);

    visit('/vox/publish')
        ->assertSee('Publish approved translations')
        ->pressAndWaitFor('Publish translations')
        ->assertSee('Published 2 translation values across 2 files.')
        ->assertNoJavaScriptErrors();

    expect(File::exists(lang_path('en/vox_browser_publish.php')))->toBeTrue()
        ->and((require lang_path('fr/vox_browser_publish.php'))['message'])
        ->toBe('Publié depuis le navigateur');
});
