<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use KeypointSolutions\LaravelVox\Models\VoxEnvironment;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => bcrypt('password')]
        );

        VoxEnvironment::query()->updateOrCreate(
            ['name' => 'Demo production'],
            [
                'type' => 'testing',
                'url' => rtrim((string) config('app.url'), '/').'/vox-demo-remote/sync',
                'secret_key' => 'vox-demo-key',
            ]
        );
    }
}
