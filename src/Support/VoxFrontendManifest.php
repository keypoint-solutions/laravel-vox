<?php

namespace KeypointSolutions\LaravelVox\Support;

use Illuminate\Support\Facades\File;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;

class VoxFrontendManifest
{
    /**
     * @param  array<string, array<string, mixed>>  $scanResults
     * @param  array<int, array{pattern?: string, prefix: string, suffix: string, is_frontend: bool}>  $dynamicKeys
     */
    public function writeFromScanResults(array $scanResults, array $dynamicKeys = []): void
    {
        $groups = [];

        foreach ($scanResults as $entry) {
            $group = $entry['group'] ?? null;

            if (($entry['is_frontend'] ?? false) !== true || ! is_string($group) || $group === '') {
                continue;
            }

            $groups[] = $group;
        }

        foreach ($dynamicKeys as $dynamicKey) {
            if (($dynamicKey['is_frontend'] ?? false) !== true) {
                continue;
            }

            $pattern = VoxDynamicKeyRegistry::normalizePattern(
                $dynamicKey['pattern'] ?? $dynamicKey['prefix'].'*'.$dynamicKey['suffix']
            );

            if (preg_match('/^([^\s.*]+)\./', $pattern, $matches) !== 1) {
                continue;
            }

            $groups[] = $matches[1];
        }

        $this->write($groups);
    }

    public function writeFromDatabase(): void
    {
        $groups = VoxTranslation::query()
            ->where('is_frontend', true)
            ->whereNotNull('group')
            ->where('group', '!=', 'json')
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
     * @return array<int, string>
     */
    public function groups(): array
    {
        $configured = $this->configuredGroups();

        if ($configured !== null) {
            return $this->normalizeGroups($configured);
        }

        $path = $this->path();

        if (File::exists($path)) {
            $manifest = json_decode(File::get($path), true);
            $groups = is_array($manifest) ? ($manifest['groups'] ?? []) : [];

            if (is_array($groups)) {
                return $this->normalizeGroups($groups);
            }
        }

        return VoxTranslation::query()
            ->where('is_frontend', true)
            ->where('is_orphan', false)
            ->whereNotNull('group')
            ->where('group', '!=', 'json')
            ->distinct()
            ->orderBy('group')
            ->pluck('group')
            ->filter(fn (mixed $group): bool => is_string($group) && $group !== '')
            ->values()
            ->all();
    }

    public function usesConfiguredGroups(): bool
    {
        return $this->configuredGroups() !== null;
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

    /**
     * @param  array<int, mixed>  $groups
     * @return array<int, string>
     */
    private function normalizeGroups(array $groups): array
    {
        $groups = array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $group): string => is_string($group) ? trim($group) : '',
                $groups
            ),
            static fn (string $group): bool => $group !== ''
        )));
        sort($groups);

        return $groups;
    }
}
