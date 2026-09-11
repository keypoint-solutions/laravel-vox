<?php

namespace KeypointSolutions\LaravelVox\Tests\Feature;

use Inertia\Testing\AssertableInertia;
use KeypointSolutions\LaravelVox\LaravelVox as LaravelVoxManager;
use KeypointSolutions\LaravelVox\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ManualRouteRegistrationTest extends TestCase
{
    public function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('vox.routes.auto_register', false);
    }

    protected function defineWebRoutes($router): void
    {
        $router
            ->prefix('admin/translations')
            ->name('admin.')
            ->group(function (): void {
                app(LaravelVoxManager::class)->routes();
            });
    }

    #[Test]
    public function it_can_register_routes_manually_inside_an_application_route_group(): void
    {
        $this->withoutVite();
        app()->detectEnvironment(fn () => 'local');

        expect(app('router')->has('vox.manage'))->toBeFalse()
            ->and(app('router')->has('admin.vox.manage'))->toBeTrue();

        $this->get('/admin/translations/manage')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Manage', false)
                ->where('vox.routes.dashboard', '/admin/translations')
                ->where('vox.routes.manage', '/admin/translations/manage')
                ->where('vox.routes.sync_locale_store', '/admin/translations/sync/locales')
                ->where('vox.routes.sync_reconcile', '/admin/translations/sync/reconcile')
                ->where('vox.routes.settings_update', '/admin/translations/settings')
            );

        $this->get('/vox/manage')->assertNotFound();
    }
}
