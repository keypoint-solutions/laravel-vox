<?php

namespace KeypointSolutions\LaravelVox\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use KeypointSolutions\LaravelVox\Database\Factories\VoxTranslationFactory;

class VoxTranslation extends VoxModel
{
    /** @use HasFactory<VoxTranslationFactory> */
    use HasFactory;

    protected $table = 'vox_translations';

    protected $fillable = [
        'key',
        'group',
        'is_frontend',
        'is_orphan',
        'is_ignored',
        'is_pending_delete',
        'source',
        'status',
    ];

    protected $casts = [
        'is_frontend' => 'bool',
        'is_orphan' => 'bool',
        'is_ignored' => 'bool',
        'is_pending_delete' => 'bool',
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
