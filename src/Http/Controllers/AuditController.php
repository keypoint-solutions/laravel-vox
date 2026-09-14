<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use KeypointSolutions\LaravelVox\Models\VoxAudit;

class AuditController
{
    public function __invoke(Request $request): Response
    {
        $perPage = $request->integer('per_page', 25);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;

        return Inertia::render('Audit', [
            'audits' => VoxAudit::query()
                ->latest('created_at')
                ->latest('id')
                ->paginate($perPage)
                ->withQueryString()
                ->through(fn (VoxAudit $audit): array => [
                    'id' => $audit->id,
                    'action' => $audit->action,
                    'context' => $audit->context ?? [],
                    'user_id' => $audit->user_id,
                    'created_at' => $audit->created_at?->toIso8601String(),
                ]),
        ]);
    }
}
