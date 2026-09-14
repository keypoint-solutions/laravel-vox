<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use KeypointSolutions\LaravelVox\Models\VoxEnvironment;
use KeypointSolutions\LaravelVox\Translation\RemoteTranslationSyncer;
use Throwable;

class PullRemoteTranslationsController
{
    public function __invoke(
        Request $request,
        VoxEnvironment $environment,
        RemoteTranslationSyncer $syncer,
    ): RedirectResponse {
        try {
            $result = $syncer->sync($environment, $request->boolean('include_drafts'));
        } catch (Throwable $exception) {
            return back()->withErrors(['sync' => $exception->getMessage()]);
        }

        return Inertia::flash(
            'success',
            "Pulled {$result} remote values from {$environment->name} for review. Local translations and files were not changed."
        )->back();
    }
}
