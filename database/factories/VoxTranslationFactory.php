<?php

namespace KeypointSolutions\LaravelVox\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Models\VoxTranslationOccurrence;
use KeypointSolutions\LaravelVox\Models\VoxTranslationValue;

/**
 * @extends Factory<VoxTranslation>
 */
class VoxTranslationFactory extends Factory
{
    protected $model = VoxTranslation::class;

    public function definition(): array
    {
        return [
            'group' => $this->faker->word(),
            'key' => $this->faker->words(2, true),
            'is_frontend' => false,
            'source' => '__',
            'status' => 'pending',
        ];
    }

    public function frontend(): static
    {
        return $this->state([
            'is_frontend' => true,
            'source' => '$t',
        ]);
    }

    public function json(): static
    {
        return $this->state([
            'group' => 'json',
            'source' => null,
        ]);
    }

    public function approved(): static
    {
        return $this->state(['status' => 'approved']);
    }

    public function pending(): static
    {
        return $this->state(['status' => 'pending']);
    }

    /**
     * @param  array<string, string>  $values
     */
    public function withValues(array $values): static
    {
        return $this->afterCreating(function (VoxTranslation $translation) use ($values): void {
            foreach ($values as $locale => $value) {
                VoxTranslationValue::query()->create([
                    'translation_id' => $translation->id,
                    'locale' => $locale,
                    'value' => $value,
                    'is_obsolete' => false,
                ]);
            }
        });
    }

    public function withOccurrence(string $filePath, int $lineNumber = 1, ?string $contextBefore = null, ?string $contextAfter = null): static
    {
        return $this->afterCreating(function (VoxTranslation $translation) use ($filePath, $lineNumber, $contextBefore, $contextAfter): void {
            VoxTranslationOccurrence::query()->create([
                'translation_id' => $translation->id,
                'file_path' => $filePath,
                'line_number' => $lineNumber,
                'context_before' => $contextBefore,
                'context_after' => $contextAfter,
            ]);
        });
    }

    public function withTimestamps(Carbon $createdAt, ?Carbon $updatedAt = null): static
    {
        return $this->afterCreating(function (VoxTranslation $translation) use ($createdAt, $updatedAt): void {
            $translation->timestamps = false;
            $translation->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $updatedAt ?? $createdAt,
            ])->save();
            $translation->timestamps = true;
        });
    }
}
