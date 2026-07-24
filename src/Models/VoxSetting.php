<?php

namespace KeypointSolutions\LaravelVox\Models;

class VoxSetting extends VoxModel
{
    protected $table = 'vox_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'value',
    ];
}
