<?php

namespace KeypointSolutions\LaravelVox\Support;

use KeypointSolutions\LaravelVox\Models\VoxAudit;

class VoxAuditLogger
{
    /**
     * @param array<string, mixed> $context
     */
    public function record(string $action, array $context = []): void
    {
        VoxAudit::create([
            'action' => $action,
            'context' => $context,
            'user_id' => auth()->id(),
            'created_at' => now(),
        ]);
    }
}
