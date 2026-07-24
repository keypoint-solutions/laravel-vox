<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;
use KeypointSolutions\LaravelVox\Translation\TranslationDatabaseSynchronizer;

class SyncLocalTranslationsController
{
    public function __invoke(
        Request $request,
        TranslationDatabaseSynchronizer $synchronizer,
        VoxAuditLogger $auditLogger,
    ): RedirectResponse {
        $validated = $request->validate([
            'update_language_files' => ['sometimes', 'boolean'],
        ]);
        $startedAt = now();
        $updateLanguageFiles = (bool) ($validated['update_language_files'] ?? false);
        $result = $synchronizer->sync($updateLanguageFiles);

        $auditLogger->record('sync', [
            'started_at' => $startedAt->toIso8601String(),
            'translations' => $result->translations(),
            'changed_translations' => $result->changedTranslations(),
            'reopened_translations' => $result->reopenedTranslations(),
            'orphan_translations' => $result->orphanTranslations(),
            'added_language_keys' => $result->addedLanguageKeys(),
            'removed_language_keys' => $result->removedLanguageKeys(),
            'updated_language_files' => $updateLanguageFiles,
            'source' => 'ui',
        ]);

        $noun = $result->translations() === 1 ? 'translation' : 'translations';

        $reviewMessage = $result->reopenedTranslations() > 0
            ? " {$result->reopenedTranslations()} approved "
                .($result->reopenedTranslations() === 1 ? 'translation was' : 'translations were')
                .' returned to review.'
            : '';

        $fileMessage = $updateLanguageFiles
            ? " Language files were updated first ({$result->addedLanguageKeys()} added, "
                ."{$result->removedLanguageKeys()} removed)."
            : '';

        $orphanMessage = $result->orphanTranslations() > 0
            ? " {$result->orphanTranslations()} "
                .($result->orphanTranslations() === 1 ? 'orphan is' : 'orphans are')
                .' available for review.'
            : '';

        return Inertia::flash(
            'success',
            "Synchronized {$result->translations()} local {$noun}.{$fileMessage}{$reviewMessage}{$orphanMessage}"
        )->back();
    }
}
