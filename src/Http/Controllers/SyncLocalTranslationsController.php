<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;
use KeypointSolutions\LaravelVox\Translation\TranslationDatabaseSynchronizer;

class SyncLocalTranslationsController
{
    public function __invoke(
        TranslationDatabaseSynchronizer $synchronizer,
        VoxAuditLogger $auditLogger,
    ): RedirectResponse {
        $startedAt = now();
        $result = $synchronizer->sync();

        $auditLogger->record('sync', [
            'started_at' => $startedAt->toIso8601String(),
            'translations' => $result->translations(),
            'changed_translations' => $result->changedTranslations(),
            'reopened_translations' => $result->reopenedTranslations(),
            'source' => 'ui',
        ]);

        $noun = $result->translations() === 1 ? 'translation' : 'translations';

        $reviewMessage = $result->reopenedTranslations() > 0
            ? " {$result->reopenedTranslations()} approved "
                .($result->reopenedTranslations() === 1 ? 'translation was' : 'translations were')
                .' returned to review.'
            : '';

        return Inertia::flash(
            'success',
            "Synchronized {$result->translations()} local {$noun}.{$reviewMessage}"
        )->back();
    }
}
