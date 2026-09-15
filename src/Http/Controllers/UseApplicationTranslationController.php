<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;
use KeypointSolutions\LaravelVox\Support\VoxLocaleResolver;
use KeypointSolutions\LaravelVox\Translation\FrontendTranslationArtifacts;
use KeypointSolutions\LaravelVox\Translation\TranslationFileRepository;
use KeypointSolutions\LaravelVox\Translation\TranslationFileTransaction;
use KeypointSolutions\LaravelVox\Translation\TranslationGroupFormat;

class UseApplicationTranslationController
{
    public function __invoke(Request $request, VoxTranslation $translation, TranslationFileRepository $files, VoxLocaleResolver $locales): RedirectResponse
    {
        abort_if($translation->is_pending_delete, 422, 'Restore this translation before changing its published wording.');
        $data = $request->validate(['locale' => ['required', Rule::in($locales->resolveLocales())]]);

        app(TranslationFileTransaction::class)->run(fn () => DB::connection(config('vox.database.connection', 'vox'))->transaction(function () use ($translation, $files, $data): void {
            $value = $translation->values()->where('locale', $data['locale'])->lockForUpdate()->firstOrFail();
            $locale = $value->locale;
            $key = $translation->key;
            if ($translation->group === null || $translation->group === 'json') {
                [$namespace, $key] = str_contains($key, '::') ? explode('::', $key, 2) : [null, $key];
                $entries = $files->loadJson($locale, $namespace);
                if ($value->file_value === null) {
                    unset($entries[$key]);
                } else {
                    $entries[$key] = $value->file_value;
                }
                if ($entries === []) {
                    app(TranslationFileTransaction::class)->delete($files->jsonPath($locale, $namespace));
                } else {
                    $files->saveJson($locale, $entries, $namespace);
                }
            } else {
                $entries = $files->loadGroup($locale, $translation->group);
                $format = app(TranslationGroupFormat::class);
                $entries = $format->normalize($entries);
                if ($value->file_value === null) {
                    $format->remove($entries, $key);
                } else {
                    $format->set($entries, $key, $value->file_value);
                }
                if ($entries === []) {
                    app(TranslationFileTransaction::class)->delete($files->groupPath($locale, $translation->group));
                } else {
                    $files->saveGroup($locale, $translation->group, $entries, [], $files->loadLineComments($locale, $translation->group), array_values($files->loadObsoleteComments($locale, $translation->group)));
                }
            }
            $value->published_override = null;
            if (! $value->is_pending_publish) {
                $value->value = $value->file_value ?? '';
                $value->is_approved = true;
            }
            $value->save();
            $translation->refreshApproval();
            if (config('vox.frontend.runtime.enabled', false)) {
                app(FrontendTranslationArtifacts::class)->publish();
            }
            app(VoxAuditLogger::class)->record('use-application-wording', ['translation_id' => $translation->id, 'locale' => $locale]);
        }));

        return Inertia::flash('success', 'Application wording is now live. Any draft was preserved.')->back();
    }
}
