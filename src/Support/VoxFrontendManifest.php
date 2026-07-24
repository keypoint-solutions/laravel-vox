<?php

namespace KeypointSolutions\LaravelVox\Support;

use Illuminate\Support\Facades\File;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;

class VoxFrontendManifest
{
    /**
     * @param  array<string, array<string, mixed>>  $scanResults
     */
    public function writeFromScanResults(array $scanResults): void
    {
        $groups = [];

        foreach ($scanResults as $entry) {
            $group = $entry['group'] ?? null;

            if (($entry['is_frontend'] ?? false) !== true || ! is_string($group) || $group === '') {
                continue;
            }

            $groups[] = $group;
        }

        $this->write($groups);
    }

    public function writeFromDatabase(): void
    {
        $groups = VoxTranslation::query()
            ->where('is_frontend', true)
            ->whereNotNull('group')
            ->distinct()
            ->orderBy('group')
            ->pluck('group')
            ->filter(fn (mixed $group): bool => is_string($group) && $group !== '')
            ->values()
            ->all();

        $this->write($groups);
    }

    /**
     * @param  array<int, string>  $detectedGroups
     */
    public function write(array $detectedGroups): void
    {
        $groups = $this->configuredGroups() ?? $detectedGroups;
        $groups = array_values(array_unique(array_filter(
            $groups,
            fn (mixed $group): bool => is_string($group) && $group !== ''
        )));
        sort($groups);

        $path = $this->path();
        $directory = dirname($path);

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        File::put($path, json_encode(
            ['groups' => $groups],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        )."\n");
    }

    public function path(): string
    {
        return (string) config('vox.frontend.manifest', storage_path('vox/frontend.json'));
    }

    /**
     * @return array<int, string>|null
     */
    private function configuredGroups(): ?array
    {
        $configured = config('vox.frontend.groups', 'auto');

        if (is_array($configured)) {
            return $configured;
        }

        if (! is_string($configured) || $configured === '' || $configured === 'auto') {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode(',', $configured))));
    }
}
