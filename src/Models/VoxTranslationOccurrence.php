<?php

namespace KeypointSolutions\LaravelVox\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoxTranslationOccurrence extends VoxModel
{
    protected $table = 'vox_translation_occurrences';

    protected $fillable = [
        'translation_id',
        'file_path',
        'line_number',
        'context_before',
        'context_after',
    ];

    public function translation(): BelongsTo
    {
        return $this->belongsTo(VoxTranslation::class, 'translation_id');
    }
}
