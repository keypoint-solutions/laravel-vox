<?php

namespace KeypointSolutions\LaravelVox\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Inertia\Middleware;
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
        return [
            ...parent::share($request),
            'vox' => [
                'features' => config('vox.features', []),
                'routes' => $this->routeUrls($request),
                'sync_enabled' => config('vox.sync.enabled', true),
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
            'manage' => route($routeNamePrefix.'manage', absolute: false),
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
            'manage_translation_translate' => route(
                $routeNamePrefix.'manage.translations.translate',
                ['translation' => '__translation__'],
                false
            ),
            'publish' => route($routeNamePrefix.'publish', absolute: false),
            'audit' => route($routeNamePrefix.'audit', absolute: false),
            'settings' => route($routeNamePrefix.'settings', absolute: false),
            'settings_update' => route($routeNamePrefix.'settings.update', absolute: false),
        ];
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
