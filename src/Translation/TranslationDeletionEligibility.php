<?php

namespace KeypointSolutions\LaravelVox\Translation;

use KeypointSolutions\LaravelVox\Models\VoxTranslation;

class TranslationDeletionEligibility
{
    public function reason(VoxTranslation $row): ?string
    {
        return $row->is_pending_delete
            ? 'Pending deletion. Publish to remove this key, or cancel deletion to keep it.'
            : null;
    }
}
