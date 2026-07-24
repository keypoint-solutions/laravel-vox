<?php

namespace KeypointSolutions\LaravelVox\Tests\Feature;

use Inertia\Testing\AssertableInertia;
use KeypointSolutions\LaravelVox\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ConfiguredRouteRegistrationTest extends TestCase
{
    public function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('vox.routes.prefix', 'translations');
    }

    #[Test]
    public function it_automatically_registers_routes_with_the_configured_prefix(): void
    {
        $this->withoutVite();
        app()->detectEnvironment(fn () => 'local');

        $this->get('/translations/manage')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Manage', false)
                ->where('vox.routes.manage', '/translations/manage')
                ->where(
                    'vox.routes.manage_translation_update',
                    '/translations/manage/translations/__translation__'
                )
                ->where(
                    'vox.routes.manage_translation_bulk_translate',
                    '/translations/manage/translations/bulk-translate'
                )
            );

        $this->get('/vox/manage')->assertNotFound();
    }
}
