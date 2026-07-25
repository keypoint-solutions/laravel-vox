<?php

namespace KeypointSolutions\LaravelVox\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class RuntimeOnlyRouteRegistrationTest extends TestCase
{
    private string $runtimePath;

    public function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('vox.gui.enabled', false);
        $app['config']->set('vox.frontend.runtime.enabled', true);
        $app['config']->set('vox.routes.prefix', 'localized');
        $app['config']->set('vox.translate.locales', ['en']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->runtimePath = base_path('tests/.tmp/runtime-only-'.Str::uuid());
        File::makeDirectory($this->runtimePath, 0755, true);
        config()->set('vox.frontend.runtime.path', $this->runtimePath);
        File::put($this->runtimePath.'/en.json', '{"message":"Hello"}');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->runtimePath);

        parent::tearDown();
    }

    #[Test]
    public function it_can_automatically_register_only_the_runtime_translation_route(): void
    {
        $this->get('/localized/translations/en')
            ->assertOk()
            ->assertExactJson(['message' => 'Hello']);

        $this->get('/localized/locales')
            ->assertOk()
            ->assertJsonPath('default_locale', 'en')
            ->assertJsonPath('locales.0.code', 'en');

        $this->get('/localized')->assertNotFound();

        $this->assertTrue(Route::has('vox.translations.show'));
        $this->assertTrue(Route::has('vox.locales.index'));
        $this->assertFalse(Route::has('vox.dashboard'));
    }
}
