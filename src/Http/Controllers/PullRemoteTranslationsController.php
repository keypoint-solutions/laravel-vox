<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use KeypointSolutions\LaravelVox\Models\VoxEnvironment;
use KeypointSolutions\LaravelVox\Translation\RemoteTranslationSyncer;
use Throwable;

class PullRemoteTranslationsController
{
    public function __invoke(
        VoxEnvironment $environment,
        RemoteTranslationSyncer $syncer,
    ): RedirectResponse {
        try {
            $result = $syncer->sync($environment);
        } catch (Throwable $exception) {
            return back()->withErrors(['sync' => $exception->getMessage()]);
        }

        $noun = $result->translations() === 1 ? 'translation' : 'translations';
        $reviewMessage = $result->reopenedTranslations() > 0
            ? " {$result->reopenedTranslations()} approved "
                .($result->reopenedTranslations() === 1 ? 'translation was' : 'translations were')
                .' returned to review.'
            : '';

        return Inertia::flash(
            'success',
            "Pulled {$result->translations()} {$noun} from {$environment->name}.{$reviewMessage}"
        )->back();
    }
}
