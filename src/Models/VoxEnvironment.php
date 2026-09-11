<?php

namespace KeypointSolutions\LaravelVox\Models;

class VoxEnvironment extends VoxModel
{
    protected $table = 'vox_environments';

    protected $casts = [
        'sync_revision' => 'int',
        'last_pulled_at' => 'datetime',
    ];

    protected $fillable = [
        'name',
        'type',
        'url',
        'secret_key',
    ];
}
