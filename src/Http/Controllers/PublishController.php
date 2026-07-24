<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use KeypointSolutions\LaravelVox\Models\VoxAudit;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;
use KeypointSolutions\LaravelVox\Support\VoxKeyProtector;
use KeypointSolutions\LaravelVox\Support\VoxLocaleResolver;
use KeypointSolutions\LaravelVox\Translation\TranslationPublisher;

class PublishController
{
    public function __construct(
        private VoxLocaleResolver $localeResolver,
        private VoxKeyProtector $keyProtector,
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
            'values' => $result->values(),
            'files' => $result->fileCount(),
            'skipped_translations' => $result->skippedTranslations(),
            'incomplete_translations' => $result->incompleteTranslations(),
            'protected_translations' => $result->protectedTranslations(),
            'orphan_translations' => $result->orphanTranslations(),
        ]);

        $message = "Published {$result->values()} translation values across {$result->fileCount()} files.";

        return Inertia::flash('success', $message)
            ->flash('publish_result', [
                'values' => $result->values(),
                'files' => $result->fileCount(),
                'skipped_translations' => $result->skippedTranslations(),
                'incomplete_translations' => $result->incompleteTranslations(),
                'protected_translations' => $result->protectedTranslations(),
                'orphan_translations' => $result->orphanTranslations(),
            ])
            ->back();
    }

    /**
     * @return array{approved: int, publishable: int, pending: int, incomplete: int, protected: int, orphan: int}
     */
    private function stats(): array
    {
        $locales = $this->localeResolver->resolveLocales();
        $baseLocale = $this->localeResolver->resolveBaseLocale($locales);

        if (! in_array($baseLocale, $locales, true)) {
            $locales[] = $baseLocale;
        }

        $prefix = (string) config('vox.parse.missing_translation_prefix', '🚩');
        $approved = VoxTranslation::query()->where('status', 'approved')->with('values')->get();
        $orphan = $approved->where('is_orphan', true)->count();
        $active = $approved->where('is_orphan', false);
        $protected = $active->filter(fn (VoxTranslation $translation): bool => $this->isProtected($translation))->count();
        $candidates = $active->reject(fn (VoxTranslation $translation): bool => $this->isProtected($translation));
        $incomplete = $candidates->filter(function (VoxTranslation $translation) use ($locales, $prefix): bool {
            $values = $translation->values->keyBy('locale');

            foreach ($locales as $locale) {
                $value = $values->get($locale)?->value;

                if (! is_string($value) || $value === '' || str_starts_with($value, $prefix)) {
                    return true;
                }
            }

            return false;
        })->count();

        return [
            'approved' => $approved->count(),
            'publishable' => $candidates->count() - $incomplete,
            'pending' => VoxTranslation::query()->where('status', 'pending')->count(),
            'incomplete' => $incomplete,
            'protected' => $protected,
            'orphan' => $orphan,
        ];
    }

    private function isProtected(VoxTranslation $translation): bool
    {
        return $this->keyProtector->isProtected(
            $translation->key,
            $translation->group === null || $translation->group === 'json'
                ? null
                : $translation->group
        );
    }
}
