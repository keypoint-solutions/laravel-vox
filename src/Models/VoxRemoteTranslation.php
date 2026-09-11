<?php

namespace KeypointSolutions\LaravelVox\Models;

class VoxRemoteTranslation extends VoxModel
{
    protected $table = 'vox_remote_translations';

    protected $fillable = [
        'environment_id',
        'identity',
        'group',
        'key',
        'locale',
        'remote_value',
        'last_seen_value',
        'base_value',
        'base_local_value',
        'has_baseline',
        'remote_present',
        'revision',
    ];

    protected $casts = [
        'has_baseline' => 'bool',
        'remote_present' => 'bool',
        'revision' => 'int',
    ];
}
