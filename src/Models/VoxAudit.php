<?php

namespace KeypointSolutions\LaravelVox\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use KeypointSolutions\LaravelVox\Database\Factories\VoxAuditFactory;

class VoxAudit extends VoxModel
{
    /** @use HasFactory<VoxAuditFactory> */
    use HasFactory;

    protected $table = 'vox_audits';

    public $timestamps = false;

    protected $fillable = [
        'action',
        'context',
        'user_id',
        'created_at',
    ];

    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime',
    ];
}
