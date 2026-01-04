<?php

namespace KeypointSolutions\LaravelVox\Support;

use Illuminate\Support\Facades\File;

class VoxDatabaseManager
{
    public function ensureConnection(): void
    {
        $connectionName = config('vox.database.connection', 'vox');
        $databasePath = config('vox.database.path');

        if ($databasePath === null) {
            return;
        }

        if (config("database.connections.{$connectionName}") === null) {
            config()->set("database.connections.{$connectionName}", [
                'driver' => 'sqlite',
                'database' => $databasePath,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);
        }

        if ($databasePath !== ':memory:') {
            $directory = dirname($databasePath);

            if (! File::exists($directory)) {
                File::makeDirectory($directory, 0755, true);
            }

            if (! File::exists($databasePath)) {
                File::put($databasePath, '');
            }
        }
    }
}
