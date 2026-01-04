<?php

namespace KeypointSolutions\LaravelVox\Models;

use Illuminate\Database\Eloquent\Model;

abstract class VoxModel extends Model
{
    public function getConnectionName(): ?string
    {
        return config('vox.database.connection', 'vox');
    }
}
