<?php

namespace KeypointSolutions\LaravelVox\Translation;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;
use KeypointSolutions\LaravelVox\Support\VoxConfig;
use KeypointSolutions\LaravelVox\Translation\Publishing\VoxFrontendManifest;
use KeypointSolutions\LaravelVox\Translation\Scanning\VoxDynamicKeyRegistry;
use RuntimeException;

class TranslationResetter
{
    public const SCOPES = ['translations', 'all'];

    public function __construct(
        private VoxAuditLogger $audit,
        private VoxDynamicKeyRegistry $dynamicKeys,
        private VoxFrontendManifest $frontendManifest,
    ) {}

    public static function confirmation(string $scope): string
    {
        return match ($scope) {
            'translations' => 'RESET TRANSLATIONS',
            'all' => 'RESET ALL VOX DATA',
            default => throw new InvalidArgumentException('Unknown Vox reset scope.'),
        };
    }

    /**
     * @return array<string, int>
     */
    public function reset(string $scope): array
    {
        self::confirmation($scope);
        $connection = VoxConfig::connection();

        $deleted = $connection->transaction(function () use ($connection, $scope): array {
            $connection->table('vox_environments')->orderBy('id')->lockForUpdate()->get();

            $tables = [
                'vox_translation_rules',
                'vox_remote_translations',
                'vox_translation_occurrences',
                'vox_translation_values',
                'vox_translations',
            ];

            if ($scope === 'all') {
                $connection->table('vox_environments')->orderBy('id')->lockForUpdate()->get();

                $tables = [...$tables, 'vox_environments', 'vox_settings', 'vox_audits'];
            }

            $deleted = [];

            foreach ($tables as $table) {
                $deleted[$table] = $connection->table($table)->delete();
            }

            if ($scope === 'translations') {
                $connection->table('vox_environments')->update([
                    'last_pulled_at' => null,
                    'sync_revision' => $connection->raw('sync_revision + 1'),
                ]);
            }

            if ($connection->getSchemaBuilder()->hasTable('vox_checkpoints')) {
                $connection->table('vox_checkpoints')->delete();
            }

            $this->audit->record('data-reset', ['scope' => $scope, 'deleted' => $deleted]);

            return $deleted;
        });

        foreach (array_unique([$this->dynamicKeys->manifestPath(), $this->frontendManifest->path()]) as $path) {
            if (File::exists($path) && ! File::delete($path)) {
                throw new RuntimeException('Vox database reset completed, but the discovery manifest could not be deleted: '.$path);
            }
        }

        app(TranslationFallbackRules::class)->clear();

        return $deleted;
    }
}
