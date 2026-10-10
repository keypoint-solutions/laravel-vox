<?php

namespace KeypointSolutions\LaravelVox\Models;

use Illuminate\Database\Eloquent\Model;
use KeypointSolutions\LaravelVox\Support\VoxConfig;

abstract class VoxModel extends Model
{
    public function getConnectionName(): ?string
    {
        return VoxConfig::connectionName();
    }
}
