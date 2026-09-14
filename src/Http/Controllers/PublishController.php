<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use KeypointSolutions\LaravelVox\Models\VoxAudit;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;
use KeypointSolutions\LaravelVox\Support\VoxDynamicKeyRegistry;
use KeypointSolutions\LaravelVox\Support\VoxLocaleResolver;
use KeypointSolutions\LaravelVox\Translation\TranslationPublisher;

class PublishController
{
    public function __construct(
        private VoxLocaleResolver $localeResolver,
        private VoxDynamicKeyRegistry $dynamicKeys,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Publish', [
            'stats' => $this->stats(),
            'lastPublishAt' => VoxAudit::query()
                ->where('action', 'publish')
                ->latest('created_at')
                ->first()?->created_at?->toIso8601String(),
        ]);
    }

    public function store(TranslationPublisher $publisher, VoxAuditLogger $auditLogger): RedirectResponse
    {
        $result = $publisher->publish();

        $auditLogger->record('publish', [
            'deleted_keys' => $result->deletedKeys(),
            'values' => $result->values(),
            'files' => $result->fileCount(),
            'skipped_translations' => $result->skippedTranslations(),
            'incomplete_translations' => $result->incompleteTranslations(),
            'orphan_translations' => $result->orphanTranslations(),
            'frontend_files' => $result->frontendFileCount(),
        ]);

        $message = "Published {$result->values()} translation values across {$result->fileCount()} files.";

        if ($result->deletedKeys() !== []) {
            $message .= ' Applied '.count($result->deletedKeys()).' pending deletions.';
        }

        if ($result->frontendFileCount() > 0) {
            $message .= " Refreshed {$result->frontendFileCount()} frontend locale bundles.";
        }

        return Inertia::flash('success', $message)
            ->flash('publish_result', [
                'deleted_keys' => $result->deletedKeys(),
                'values' => $result->values(),
                'files' => $result->fileCount(),
                'skipped_translations' => $result->skippedTranslations(),
                'incomplete_translations' => $result->incompleteTranslations(),
                'orphan_translations' => $result->orphanTranslations(),
                'frontend_files' => $result->frontendFileCount(),
            ])
            ->back();
    }

    /**
     * @return array{pending_deletions: int, approved: int, publishable: int, publishable_values: int, pending: int, incomplete: int, dynamic: int, orphan: int}
     */
    private function stats(): array
    {
        $locales = $this->localeResolver->resolveLocales();
        $baseLocale = $this->localeResolver->resolveBaseLocale($locales);

        if (! in_array($baseLocale, $locales, true)) {
            $locales[] = $baseLocale;
        }

        $prefix = (string) config('vox.parse.missing_translation_prefix', '🚩');
        $approved = VoxTranslation::query()
            ->where('is_ignored', false)
            ->whereHas('values', fn (Builder $query): Builder => $query->where('is_approved', true)->whereIn('locale', $locales))
            ->with('values')
            ->get();
        $orphan = $approved->where('is_orphan', true)->count();
        $active = $approved->where('is_orphan', false);
        $dynamic = $active->filter(
            fn (VoxTranslation $translation): bool => $this->dynamicKeys->matches(
                $translation->key,
                $translation->group === 'json' ? null : $translation->group
            )
        )->count();
        $publishable = 0;
        $publishableValues = 0;
        $incomplete = 0;

        foreach ($active as $translation) {
            $hasPublishableValue = false;
            $hasIncompleteValue = false;

            foreach ($translation->values as $translationValue) {
                if (! $translationValue->is_approved || ! in_array($translationValue->locale, $locales, true)) {
                    continue;
                }

                if (! $translationValue->is_pending_publish && $translationValue->file_value !== null) {
                    continue;
                }

                $value = $translationValue->value;

                if (! is_string($value) || $value === '' || str_starts_with($value, $prefix)) {
                    $hasIncompleteValue = true;

                    continue;
                }

                $hasPublishableValue = true;
                $publishableValues++;
            }

            $publishable += (int) $hasPublishableValue;
            $incomplete += (int) $hasIncompleteValue;
        }

        return [
            'pending_deletions' => VoxTranslation::query()->where('is_pending_delete', true)->count(),
            'approved' => $approved->count(),
            'publishable' => $publishable,
            'publishable_values' => $publishableValues,
            'pending' => VoxTranslation::query()->where('status', 'pending')->count(),
            'incomplete' => $incomplete,
            'dynamic' => $dynamic,
            'orphan' => $orphan,
        ];
    }
}
