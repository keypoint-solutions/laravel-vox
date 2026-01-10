<?php

namespace KeypointSolutions\LaravelVox\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use KeypointSolutions\LaravelVox\Models\VoxAudit;

/**
 * @extends Factory<VoxAudit>
 */
class VoxAuditFactory extends Factory
{
    protected $model = VoxAudit::class;

    public function definition(): array
    {
        return [
            'action' => 'sync',
            'context' => [],
            'user_id' => null,
            'created_at' => now(),
        ];
    }

    public function sync(int $translationCount = 0): static
    {
        return $this->state([
            'action' => 'sync',
            'context' => ['translations' => $translationCount],
        ]);
    }

    public function syncRemote(): static
    {
        return $this->state(['action' => 'sync-remote']);
    }
}

