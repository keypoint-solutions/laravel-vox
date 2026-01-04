<?php

namespace KeypointSolutions\LaravelVox\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class VoxTranslation extends VoxModel
{
    protected $table = 'vox_translations';

    protected $fillable = [
        'key',
        'group',
        'is_frontend',
        'source',
        'status',
    ];

    protected $casts = [
        'is_frontend' => 'bool',
    ];

    public function values(): HasMany
    {
        return $this->hasMany(VoxTranslationValue::class, 'translation_id');
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(VoxTranslationOccurrence::class, 'translation_id');
    }
}
