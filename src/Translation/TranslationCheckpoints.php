<?php

namespace KeypointSolutions\LaravelVox\Translation;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use KeypointSolutions\LaravelVox\Support\VoxMutationLock;
use KeypointSolutions\LaravelVox\Support\VoxSettingsRepository;
use RuntimeException;
use Throwable;
use WeakMap;

class TranslationCheckpoints
{
    private const TABLES = ['vox_translations', 'vox_translation_values', 'vox_translation_occurrences', 'vox_remote_translations', 'vox_translation_rules', 'vox_settings'];

    private bool $active = false;

    /** @var array<string, array<string|int, array<string, mixed>>> */
    private array $originalRows = [];

    /** @var array<string, array{contents: string|null, mode: int|null}> */
    private array $originalFiles = [];

    /** @var WeakMap<Connection, bool> */
    private WeakMap $listening;

    public function __construct()
    {
        $this->listening = new WeakMap;
    }

    public function available(): bool
    {
        try {
            return $this->connection()->getSchemaBuilder()->hasTable('vox_checkpoints');
        } catch (Throwable) {
            return false;
        }
    }

    public function run(string $label, Closure $callback): mixed
    {
        if ($this->active || str_ends_with($label, 'settings.reset') || ! $this->available()) {
            return $callback();
        }

        $connection = $this->connection();
        $this->listen($connection);
        $this->active = true;

        $checkpointId = null;
        try {
            return $connection->transaction(function () use ($callback, $label, &$checkpointId): mixed {
                $result = $callback();
                $changes = $this->changes();
                if ($changes['rows'] !== [] || $changes['files'] !== []) {
                    $checkpointId = $this->store('Before '.$this->label($label), false, $changes);
                }

                return $result;
            });
        } catch (Throwable $exception) {
            if ($checkpointId !== null && $connection->table('vox_checkpoints')->where('id', $checkpointId)->exists()) {
                throw $exception;
            }
            foreach (array_reverse($this->originalFiles, true) as $path => $original) {
                if ($original['contents'] === null) {
                    File::delete($path);
                } else {
                    File::ensureDirectoryExists(dirname($path));
                    File::replace($path, $original['contents'], $original['mode']);
                }
            }
            throw $exception;
        } finally {
            $this->active = false;
            $this->originalRows = [];
            $this->originalFiles = [];
        }
    }

    public function create(string $label): int
    {
        return app(VoxMutationLock::class)->run(fn (): int => $this->store($label, true, ['rows' => [], 'files' => []]));
    }

    public function prune(): int
    {
        if (! $this->available()) {
            return 0;
        }

        return app(VoxMutationLock::class)->run(function (): int {
            $limit = app(VoxSettingsRepository::class)->checkpointLimit();
            $oldestRetained = $this->connection()->table('vox_checkpoints')->orderByDesc('id')->skip($limit - 1)->value('id');

            return $oldestRetained === null ? 0 : $this->connection()->table('vox_checkpoints')->where('id', '<', $oldestRetained)->delete();
        });
    }

    public function rememberFile(string $path): void
    {
        if (! $this->active || isset($this->originalFiles[$path]) || ! $this->tracksPath($path)) {
            return;
        }

        $this->originalFiles[$path] = [
            'contents' => File::isFile($path) ? File::get($path) : null,
            'mode' => File::isFile($path) ? fileperms($path) & 07777 : null,
        ];
    }

    public function restore(int $id): void
    {
        app(VoxMutationLock::class)->run(function () use ($id): void {
            $target = $this->connection()->table('vox_checkpoints')->find($id);
            if ($target === null) {
                throw new RuntimeException('This checkpoint no longer exists.');
            }

            $rows = [];
            $files = [];
            foreach ($this->connection()->table('vox_checkpoints')->where('id', '>=', $id)->orderBy('id')->cursor() as $checkpoint) {
                $changes = json_decode($checkpoint->changes, true, flags: JSON_THROW_ON_ERROR);
                foreach ($changes['rows'] as $table => $entries) {
                    foreach ($entries as $key => $entry) {
                        if (! isset($rows[$table][$key])) {
                            $rows[$table][$key] = $entry;
                        } else {
                            $rows[$table][$key]['after'] = $entry['after'];
                        }
                    }
                }
                foreach ($changes['files'] as $path => $entry) {
                    if (! isset($files[$path])) {
                        $files[$path] = $entry;
                    } else {
                        $files[$path]['after_hash'] = $entry['after_hash'];
                    }
                }
            }

            foreach ($files as $path => $entry) {
                if (! $this->tracksPath($path) || $this->fileHash($path) !== $entry['after_hash']) {
                    throw new RuntimeException('A translation file changed outside the recorded history. Restore was cancelled: '.$path);
                }
            }
            foreach ($rows as $table => $entries) {
                if (! in_array($table, self::TABLES, true)) {
                    throw new RuntimeException('The checkpoint contains an unsupported table.');
                }
                foreach ($entries as $key => $entry) {
                    $current = $this->connection()->table($table)->where($this->keyColumn($table), $key)->first();
                    if (! $this->sameRow($current ? (array) $current : null, $entry['after'])) {
                        throw new RuntimeException('Translation data changed outside the recorded history. Restore was cancelled.');
                    }
                    if ($table === 'vox_remote_translations' && $entry['before'] !== null && ! $this->connection()->table('vox_environments')->where('id', $entry['before']['environment_id'])->exists()) {
                        throw new RuntimeException('A source environment was removed after this checkpoint. Restore was cancelled.');
                    }
                }
            }

            foreach ($rows['vox_translations'] ?? [] as $key => $entry) {
                if ($entry['before'] !== null) {
                    continue;
                }
                foreach (['vox_translation_values', 'vox_translation_occurrences'] as $childTable) {
                    foreach ($this->connection()->table($childTable)->where('translation_id', $key)->pluck('id') as $childId) {
                        if (! isset($rows[$childTable][$childId]) || $rows[$childTable][$childId]['before'] !== null) {
                            throw new RuntimeException('Related translation data was added outside the recorded history. Restore was cancelled.');
                        }
                    }
                }
            }

            foreach (array_reverse(self::TABLES) as $table) {
                foreach ($rows[$table] ?? [] as $key => $entry) {
                    if ($entry['before'] === null) {
                        $this->connection()->table($table)->where($this->keyColumn($table), $key)->delete();
                    }
                }
            }
            foreach (self::TABLES as $table) {
                foreach ($rows[$table] ?? [] as $key => $entry) {
                    if ($entry['before'] !== null) {
                        $this->connection()->table($table)->updateOrInsert([$this->keyColumn($table) => $key], $entry['before']);
                    }
                }
            }
            foreach ($files as $path => $entry) {
                if ($entry['before'] === null) {
                    app(TranslationFileTransaction::class)->delete($path);
                } else {
                    $contents = base64_decode($entry['before'], true);
                    if ($contents === false) {
                        throw new RuntimeException('The checkpoint contains invalid file contents.');
                    }
                    app(TranslationFileTransaction::class)->replace($path, $contents);
                    if ($entry['mode'] !== null) {
                        chmod($path, $entry['mode']);
                    }
                }
            }
            foreach ($files as $path => $entry) {
                if ($entry['before'] === null) {
                    $this->removeEmptyLanguageDirectories(dirname($path));
                }
            }
            $this->connection()->table('vox_environments')->increment('sync_revision');
            app(TranslationFallbackRules::class)->clear();
        }, 'restoring checkpoint #'.$id);
    }

    private function listen(Connection $connection): void
    {
        if (isset($this->listening[$connection])) {
            return;
        }
        $this->listening[$connection] = true;
        $connection->beforeExecuting(function (string $sql) use ($connection): void {
            if (! $this->active || preg_match('/^\s*(?:insert(?:\s+or\s+\w+)?\s+into|replace\s+into|update|delete\s+from)\s+["`]?([a-zA-Z0-9_]+)/i', $sql, $match) !== 1) {
                return;
            }
            $table = $match[1];
            $prefix = $connection->getTablePrefix();
            if ($prefix !== '' && str_starts_with($table, $prefix)) {
                $table = substr($table, strlen($prefix));
            }
            if (! in_array($table, self::TABLES, true)) {
                return;
            }
            $this->rememberTable($table);
            if ($table === 'vox_translations') {
                $this->rememberTable('vox_translation_values');
                $this->rememberTable('vox_translation_occurrences');
            }
        });
    }

    private function rememberTable(string $table): void
    {
        if (! array_key_exists($table, $this->originalRows)) {
            $this->originalRows[$table] = $this->readRows($table);
        }
    }

    /** @return array<string|int, array<string, mixed>> */
    private function readRows(string $table): array
    {
        $query = $this->connection()->table($table);
        if ($table === 'vox_settings') {
            $query->where('key', 'provisioned_locales');
        }

        return $query->get()->mapWithKeys(fn ($row): array => [$row->{$this->keyColumn($table)} => (array) $row])->all();
    }

    /** @return array{rows: array, files: array} */
    private function changes(): array
    {
        $rows = [];
        foreach ($this->originalRows as $table => $before) {
            $after = $this->readRows($table);
            foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
                if (! $this->sameRow($before[$key] ?? null, $after[$key] ?? null)) {
                    $rows[$table][$key] = ['before' => $before[$key] ?? null, 'after' => $after[$key] ?? null];
                }
            }
        }
        $files = [];
        foreach ($this->originalFiles as $path => $before) {
            $afterHash = $this->fileHash($path);
            if (($before['contents'] === null ? null : hash('sha256', $before['contents'])) !== $afterHash) {
                $files[$path] = ['before' => $before['contents'] === null ? null : base64_encode($before['contents']), 'mode' => $before['mode'], 'after_hash' => $afterHash];
            }
        }

        return ['rows' => $rows, 'files' => $files];
    }

    private function sameRow(?array $left, ?array $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }
        unset($left['updated_at'], $right['updated_at']);

        return $left == $right;
    }

    private function removeEmptyLanguageDirectories(string $directory): void
    {
        $root = rtrim((string) config('vox.paths.lang', lang_path()), DIRECTORY_SEPARATOR);
        while (str_starts_with($directory, $root.DIRECTORY_SEPARATOR) && is_dir($directory) && ! is_link($directory)) {
            if (count(scandir($directory) ?: []) !== 2 || ! rmdir($directory)) {
                break;
            }
            $directory = dirname($directory);
        }
    }

    private function fileHash(string $path): ?string
    {
        return File::isFile($path) ? hash('sha256', File::get($path)) : null;
    }

    private function tracksPath(string $path): bool
    {
        $directories = [
            (string) config('vox.paths.lang', lang_path()),
            (string) config('vox.frontend.runtime.path', storage_path('vox/frontend-translations')),
        ];
        foreach ($directories as $directory) {
            if (str_starts_with($path, rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
                return ! str_contains($path, DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR);
            }
        }

        return in_array($path, [config('vox.frontend.manifest', storage_path('vox/frontend.json')), config('vox.dynamic_keys.manifest', storage_path('vox/dynamic.json'))], true);
    }

    private function keyColumn(string $table): string
    {
        return $table === 'vox_settings' ? 'key' : 'id';
    }

    private function label(string $label): string
    {
        $actions = [
            'sync.local' => 'syncing local files',
            'sync.archive.import' => 'importing translation files',
            'sync.locales.store' => 'adding a language',
            'sync.locales.destroy' => 'removing a language',
            'sync.reconcile' => 'accepting incoming wording',
            'manage.translations.update' => 'editing translations',
            'manage.translations.toggle-approval' => 'changing approval',
            'manage.translations.bulk-approval' => 'changing approvals',
            'manage.translations.bulk-translate' => 'AI translation',
            'manage.translations.use-application' => 'removing a published override',
            'checkpoints.restore' => 'restoring a checkpoint',
            'publish.store' => 'publishing translations',
        ];
        foreach ($actions as $suffix => $description) {
            if (str_ends_with($label, $suffix)) {
                return $description;
            }
        }

        return $label;
    }

    private function connection(): Connection
    {
        return DB::connection(config('vox.database.connection', 'vox'));
    }

    private function store(string $label, bool $manual, array $changes): int
    {
        $id = $this->connection()->table('vox_checkpoints')->insertGetId([
            'label' => $label,
            'is_manual' => $manual,
            'changes' => json_encode($changes, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            'created_at' => now(),
        ]);
        $this->prune();

        return $id;
    }
}
