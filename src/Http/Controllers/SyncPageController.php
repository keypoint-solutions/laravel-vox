<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use KeypointSolutions\LaravelVox\Models\VoxAudit;
use KeypointSolutions\LaravelVox\Models\VoxEnvironment;
use KeypointSolutions\LaravelVox\Support\VoxLocaleCatalog;
use KeypointSolutions\LaravelVox\Translation\RemoteReconciliation;

class SyncPageController
{
    public function __invoke(Request $request, VoxLocaleCatalog $localeCatalog, RemoteReconciliation $reconciliation): Response
    {
        $catalog = $localeCatalog->all();
        $driver = (string) config('vox.translate.driver', 'openai');
        $aiAvailable = $driver !== 'null'
            && ($driver !== 'openai' || filled(config('vox.translate.providers.openai.api_key')));

        return Inertia::render('Sync', [
            'reconciliation' => $reconciliation->page([
                'environment_id' => $request->integer('environment_id') ?: null,
                'state' => $request->string('state')->toString(),
                'locale' => $request->string('locale')->toString(),
                'search' => $request->string('search')->toString(),
            ], $request->integer('review_page', 1)),
            'environments' => VoxEnvironment::query()
                ->orderBy('name')
                ->get()
                ->map(fn (VoxEnvironment $environment): array => [
                    'id' => $environment->id,
                    'name' => $environment->name,
                    'type' => $environment->type,
                    'url' => $environment->url,
                    'secret_key_set' => $environment->secret_key !== '',
                    'updated_at' => $environment->updated_at?->toIso8601String(),
                    'last_pulled_at' => $environment->last_pulled_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'lastSyncAt' => VoxAudit::query()
                ->whereIn('action', ['sync', 'sync-remote'])
                ->latest('created_at')
                ->first()?->created_at?->toIso8601String(),
            'locales' => $catalog['locales'],
            'baseLocale' => $catalog['default_locale'],
            'ai' => [
                'available' => $aiAvailable,
                'driver' => $driver,
            ],
        ]);
    }
}
