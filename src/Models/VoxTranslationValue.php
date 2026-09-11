<?php

namespace KeypointSolutions\LaravelVox\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoxTranslationValue extends VoxModel
{
    protected $table = 'vox_translation_values';

    protected $fillable = [
        'translation_id',
        'locale',
        'value',
        'is_obsolete',
        'is_pending_publish',
    ];

    protected $casts = [
        'is_obsolete' => 'bool',
        'is_pending_publish' => 'bool',
    ];

    public function translation(): BelongsTo
    {
        return $this->belongsTo(VoxTranslation::class, 'translation_id');
    }
}
