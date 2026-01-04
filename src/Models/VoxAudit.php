<?php

namespace KeypointSolutions\LaravelVox\Models;

class VoxAudit extends VoxModel
{
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
