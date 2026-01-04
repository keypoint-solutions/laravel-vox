<?php

namespace KeypointSolutions\LaravelVox\Models;

class VoxEnvironment extends VoxModel
{
    protected $table = 'vox_environments';

    protected $fillable = [
        'name',
        'type',
        'url',
        'secret_key',
    ];
}
