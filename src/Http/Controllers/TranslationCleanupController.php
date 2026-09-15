<?php

namespace KeypointSolutions\LaravelVox\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;
use KeypointSolutions\LaravelVox\Support\VoxDynamicKeyRegistry;
use KeypointSolutions\LaravelVox\Translation\TranslationDeletionEligibility;

class TranslationCleanupController
{
    public function __invoke(Request $request, VoxDynamicKeyRegistry $registry, TranslationDeletionEligibility $eligibility, VoxAuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'distinct'],
            'action' => ['required', Rule::in(['delete', 'restore'])],
            'confirmation' => ['required', Rule::in(['CONFIRM'])],
        ]);
        DB::connection(config('vox.database.connection', 'vox'))->transaction(function () use ($data, $registry, $eligibility, $audit): void {
            $rows = VoxTranslation::query()->whereIn('id', $data['ids'])->lockForUpdate()->with('values')->get();
            if ($rows->count() !== count($data['ids'])) {
                throw ValidationException::withMessages(['cleanup' => 'The selection changed. Refresh and try again.']);
            }
            foreach ($rows as $row) {
                if ($data['action'] === 'delete') {
                    $fullKey = $registry->fullKey($row->key, $row->group);
                    $reason = $eligibility->reason($row);
                    if ($reason !== null) {
                        throw ValidationException::withMessages(['cleanup' => "[$fullKey] ".$reason]);
                    }
                }
            }
            $audit->record('translations.'.$data['action'], [
                'keys' => $rows->map(fn (VoxTranslation $row): array => ['group' => $row->group, 'key' => $row->key])->all(),
                'keys_count' => $rows->count(),
                'values_count' => $rows->sum(fn (VoxTranslation $row): int => $row->values->count()),
                'published_files_untouched' => true,
                'pending_deletion' => $data['action'] === 'delete',
            ]);
            foreach ($rows as $row) {
                if ($data['action'] === 'delete') {
                    $row->update(['is_pending_delete' => true]);
                } else {
                    $row->update(['is_pending_delete' => false]);
                }
            }
        });

        return back()->with('success', 'Translation selection updated.');
    }
}
