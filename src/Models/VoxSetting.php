<?php

namespace KeypointSolutions\LaravelVox\Models;

class VoxSetting extends VoxModel
{
    protected $table = 'vox_settings';

    protected $fillable = [
        'key',
        'value',
    ];
}
