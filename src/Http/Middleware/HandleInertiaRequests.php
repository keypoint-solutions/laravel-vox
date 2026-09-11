<?php

namespace KeypointSolutions\LaravelVox\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Inertia\Middleware;
use KeypointSolutions\LaravelVox\Support\VoxSettingsRepository;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    // protected $rootView = 'vendor.vox.app';
    protected $rootView = 'vox::app';

    public function handle(Request $request, Closure $next): Response
    {
        Vite::useBuildDirectory('vendor/vox');
        Vite::useHotFile(public_path('vendor/vox/hot'));

        return parent::handle($request, $next);
    }

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $features = config('vox.features', []);
        $features['settings'] = ($features['settings'] ?? true) && $this->canManageSettings($request);

        return [
            ...parent::share($request),
            'vox' => [
                'features' => $features,
                'routes' => $this->routeUrls($request),
                'sync_enabled' => (bool) app(VoxSettingsRepository::class)->get(
                    'sync_enabled',
                    config('vox.sync.enabled', true)
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function routeUrls(Request $request): array
    {
        $routeNamePrefix = $this->routeNamePrefix($request);

        return [
            'dashboard' => route($routeNamePrefix.'dashboard', absolute: false),
            'sync' => route($routeNamePrefix.'sync', absolute: false),
            'sync_remote' => route($routeNamePrefix.'sync.remote', absolute: false),
            'sync_local' => route($routeNamePrefix.'sync.local', absolute: false),
            'sync_reconcile' => route($routeNamePrefix.'sync.reconcile', absolute: false),
            'sync_locale_store' => route($routeNamePrefix.'sync.locales.store', absolute: false),
            'sync_archive_download' => route($routeNamePrefix.'sync.archive.download', absolute: false),
            'sync_archive_import' => route($routeNamePrefix.'sync.archive.import', absolute: false),
            'sync_environment_store' => route($routeNamePrefix.'sync.environments.store', absolute: false),
            'sync_environment_update' => route(
                $routeNamePrefix.'sync.environments.update',
                ['environment' => '__environment__'],
                false
            ),
            'sync_environment_destroy' => route(
                $routeNamePrefix.'sync.environments.destroy',
                ['environment' => '__environment__'],
                false
            ),
            'sync_environment_pull' => route(
                $routeNamePrefix.'sync.environments.pull',
                ['environment' => '__environment__'],
                false
            ),
            'manage' => route($routeNamePrefix.'manage', absolute: false),
            'manage_translation_store' => route(
                $routeNamePrefix.'manage.translations.store',
                absolute: false
            ),
            'manage_translation_translate_draft' => route(
                $routeNamePrefix.'manage.translations.translate-draft',
                absolute: false
            ),
            'manage_translation_update' => route(
                $routeNamePrefix.'manage.translations.update',
                ['translation' => '__translation__'],
                false
            ),
            'manage_translation_toggle_approval' => route(
                $routeNamePrefix.'manage.translations.toggle-approval',
                ['translation' => '__translation__'],
                false
            ),
            'manage_translation_bulk_approval' => route(
                $routeNamePrefix.'manage.translations.bulk-approval',
                absolute: false
            ),
            'manage_translation_bulk_translate' => route(
                $routeNamePrefix.'manage.translations.bulk-translate',
                absolute: false
            ),
            'manage_translation_translate' => route(
                $routeNamePrefix.'manage.translations.translate',
                ['translation' => '__translation__'],
                false
            ),
            'publish' => route($routeNamePrefix.'publish', absolute: false),
            'publish_store' => route($routeNamePrefix.'publish.store', absolute: false),
            'audit' => route($routeNamePrefix.'audit', absolute: false),
            'settings' => route($routeNamePrefix.'settings', absolute: false),
            'settings_update' => route($routeNamePrefix.'settings.update', absolute: false),
            'settings_ai_models_refresh' => route(
                $routeNamePrefix.'settings.ai.models.refresh',
                absolute: false
            ),
        ];
    }

    private function canManageSettings(Request $request): bool
    {
        if (config('vox.system.bypass_auth_in_local') && app()->environment('local')) {
            return true;
        }

        $user = $request->user();

        return $user !== null && Gate::forUser($user)->check('manageVoxSettings');
    }

    private function routeNamePrefix(Request $request): string
    {
        $currentRouteName = $request->route()?->getName();

        if (! is_string($currentRouteName)) {
            return 'vox.';
        }

        $voxPosition = strrpos($currentRouteName, 'vox.');

        if ($voxPosition === false) {
            return 'vox.';
        }

        return substr($currentRouteName, 0, $voxPosition + strlen('vox.'));
    }
}
