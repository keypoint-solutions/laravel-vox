<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;
use KeypointSolutions\LaravelVox\Support\VoxConfig;
use KeypointSolutions\LaravelVox\Translation\TranslationCheckpoints;
use RuntimeException;

class CheckpointController
{
    public function index(TranslationCheckpoints $checkpoints): Response
    {
        $available = $checkpoints->available();

        return Inertia::render('Checkpoints', [
            'available' => $available,
            'checkpoints' => $available ? VoxConfig::connection()
                ->table('vox_checkpoints')->latest('id')->paginate(25)->through(function ($checkpoint): array {
                    $changes = json_decode($checkpoint->changes, true, flags: JSON_THROW_ON_ERROR);

                    return [
                        'id' => $checkpoint->id,
                        'label' => $checkpoint->label,
                        'is_manual' => (bool) $checkpoint->is_manual,
                        'created_at' => CarbonImmutable::parse($checkpoint->created_at)->toIso8601String(),
                        'rows' => array_sum(array_map('count', $changes['rows'])),
                        'files' => count($changes['files']),
                    ];
                }) : null,
        ]);
    }

    public function store(Request $request, TranslationCheckpoints $checkpoints): RedirectResponse
    {
        $data = $request->validate(['label' => ['required', 'string', 'max:200']]);
        $checkpoints->create($data['label']);

        return Inertia::flash('success', 'Checkpoint created. Future changes can be rolled back to this point.')->back();
    }

    public function restore(Request $request, int $checkpoint, TranslationCheckpoints $checkpoints, VoxAuditLogger $audit): RedirectResponse
    {
        $request->validate(['confirm' => ['required', 'accepted']]);
        try {
            $checkpoints->restore($checkpoint);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['checkpoint' => $exception->getMessage()]);
        }
        $audit->record('checkpoint-restored', ['checkpoint_id' => $checkpoint]);

        return Inertia::flash('success', 'Checkpoint restored. A new checkpoint preserves the changes you just rolled back.')->back();
    }
}
