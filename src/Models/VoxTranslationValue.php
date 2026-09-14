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
        'file_value',
        'published_override',
        'is_approved',
    ];

    protected $casts = [
        'is_obsolete' => 'bool',
        'is_pending_publish' => 'bool',
        'is_approved' => 'bool',
    ];

    public function saveDraft(string $value, bool $approved = false): void
    {
        if ($this->exists && $this->value === $value && ! $approved) {
            return;
        }

        $this->fill([
            'value' => $value,
            'is_obsolete' => false,
            'is_pending_publish' => true,
            'is_approved' => $approved,
        ])->save();
        $this->translation->refreshApproval();
        $this->translation->touch();
    }

    public function liveValue(): ?string
    {
        return $this->published_override ?? $this->file_value;
    }

    public function translation(): BelongsTo
    {
        return $this->belongsTo(VoxTranslation::class, 'translation_id');
    }
}
