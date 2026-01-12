<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use KeypointSolutions\LaravelVox\Models\VoxAudit;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Models\VoxTranslationValue;
use KeypointSolutions\LaravelVox\Support\VoxLocaleResolver;

class DashboardController
{
    public function __construct(private VoxLocaleResolver $localeResolver) {}

    public function __invoke(): Response
    {
        return Inertia::render('Dashboard', [
            'stats' => $this->getStats(),
        ]);
    }

    /**
     * @return array{totalKeys: int, locales: int, pendingReview: int, lastSync: string|null}
     */
    private function getStats(): array
    {
        $locales = $this->localeResolver->resolveLocales();

        $lastSync = VoxAudit::query()
            ->whereIn('action', ['sync', 'sync-remote'])
            ->orderByDesc('created_at')
            ->first()?->created_at;

        return [
            'totalKeys' => VoxTranslation::query()->count(),
            'locales' => count($locales),
            'pendingReview' => VoxTranslation::query()->where('status', 'pending')->count(),
            'lastSync' => $lastSync?->diffForHumans(),
        ];
    }
}

