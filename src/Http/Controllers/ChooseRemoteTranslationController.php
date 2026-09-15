<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use KeypointSolutions\LaravelVox\Translation\Drivers\TranslationChoiceDriver;
use KeypointSolutions\LaravelVox\Translation\Drivers\TranslationDriverFactory;
use KeypointSolutions\LaravelVox\Translation\RemoteReconciliation;
use Throwable;

class ChooseRemoteTranslationController
{
    public function __invoke(Request $request, RemoteReconciliation $reconciliation, TranslationDriverFactory $factory): RedirectResponse
    {
        $bulk = $request->has('entries');
        $rules = [
            'id' => ['required', 'integer'],
            'token' => ['required', 'string', 'size:64'],
            'local' => ['present', 'nullable', 'string', 'max:1000000'],
            'incoming' => ['present', 'nullable', 'string', 'max:1000000'],
        ];
        $validated = $request->validate($bulk ? [
            'entries' => ['required', 'array', 'min:1', 'max:100'],
            ...collect($rules)->mapWithKeys(fn (array $rules, string $key): array => ['entries.*.'.$key => $key === 'id' ? [...$rules, 'distinct'] : $rules])->all(),
        ] : $rules);
        $entries = $bulk ? $validated['entries'] : [$validated];
        $rows = $reconciliation->reviewCandidates($entries);
        $driver = $factory->make();
        if (! $driver instanceof TranslationChoiceDriver) {
            throw ValidationException::withMessages(['choice' => 'The configured AI driver does not support choosing wording.']);
        }
        $contexts = [];
        foreach ($entries as $entry) {
            $row = $rows[$entry['id']];
            if (! $row['locale_available']) {
                throw ValidationException::withMessages(['choice' => 'Configure this language locally before choosing its wording.']);
            }
            $contexts[$row['id']] = [
                'local' => $entry['local'] ?? '',
                'incoming' => $entry['incoming'] ?? '',
                'locale' => $row['locale'],
                'default_locale' => $row['default_locale'],
                'default_value' => $row['default_value'],
                'key' => $row['group'].'.'.$row['key'],
                'missing_prefix' => (string) config('vox.parse.missing_translation_prefix', '🚩'),
            ];
        }
        if (strlen(json_encode($contexts, JSON_THROW_ON_ERROR)) > 120000) {
            throw ValidationException::withMessages(['choice' => 'This selection has too much text for one AI request. Select fewer rows.']);
        }
        try {
            $choices = $bulk ? $driver->chooseMany($contexts) : [array_key_first($contexts) => $driver->choose(reset($contexts))];
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['choice' => 'AI could not choose wording. Your edits are still here; try again or select a version yourself.']);
        }

        $reconciliation->reviewCandidates($entries);
        $results = [];
        foreach ($rows as $row) {
            $results[] = ['id' => $row['id'], 'token' => $row['token'], ...$choices[$row['id']]];
        }

        return Inertia::flash($bulk ? 'translationChoices' : 'translationChoice', $bulk ? $results : $results[0])->back();
    }
}
