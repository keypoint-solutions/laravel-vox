<?php

namespace KeypointSolutions\LaravelVox\Translation;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use KeypointSolutions\LaravelVox\Models\VoxEnvironment;
use KeypointSolutions\LaravelVox\Models\VoxRemoteTranslation;
use KeypointSolutions\LaravelVox\Models\VoxTranslation;
use KeypointSolutions\LaravelVox\Models\VoxTranslationValue;
use KeypointSolutions\LaravelVox\Support\VoxAuditLogger;
use KeypointSolutions\LaravelVox\Support\VoxLocaleResolver;

class RemoteReconciliation
{
    public function __construct(
        private RemoteTranslationSnapshot $snapshot,
        private VoxAuditLogger $auditLogger,
        private VoxLocaleResolver $locales,
        private TranslationFileRepository $files,
    ) {}

    /**
     * @param  array<int, array{group: string, key: string, locale: string, value: string}>  $values
     */
    public function ingest(VoxEnvironment $environment, array $values): int
    {
        $values = $this->snapshot->validate($values);

        return DB::connection($environment->getConnectionName())->transaction(function () use ($environment, $values): int {
            $lockedEnvironment = VoxEnvironment::query()->lockForUpdate()->findOrFail($environment->id);

            if ((int) $lockedEnvironment->sync_revision !== (int) $environment->sync_revision || $lockedEnvironment->url !== $environment->url) {
                $this->stale();
            }

            $existing = VoxRemoteTranslation::query()->where('environment_id', $environment->id)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('identity');
            $local = $this->localTranslations(array_column($values, 'key'), true);
            $seen = [];

            foreach ($values as $value) {
                $identity = RemoteTranslationSnapshot::identity($value['group'], $value['key'], $value['locale']);
                $candidate = $existing->get($identity) ?? new VoxRemoteTranslation([
                    'environment_id' => $environment->id,
                    'identity' => $identity,
                    'group' => $value['group'],
                    'key' => $value['key'],
                    'locale' => $value['locale'],
                    'has_baseline' => false,
                    'revision' => 0,
                ]);
                $translation = $local[RemoteTranslationSnapshot::identity($value['group'], $value['key'])] ?? null;
                $localValue = $translation?->values->firstWhere('locale', $value['locale'])?->value;

                if ($candidate->exists && $candidate->remote_present && $localValue === $candidate->remote_value) {
                    $candidate->base_value = $localValue;
                    $candidate->base_local_value = $localValue;
                    $candidate->has_baseline = true;
                }

                $candidate->last_seen_value = $candidate->exists ? $candidate->remote_value : null;
                $candidate->remote_value = $value['value'];
                $candidate->remote_present = true;
                $candidate->revision++;

                if ($localValue === $value['value']) {
                    $candidate->base_value = $localValue;
                    $candidate->base_local_value = $localValue;
                    $candidate->has_baseline = true;
                }

                $candidate->save();
                $seen[$identity] = true;
            }

            foreach ($existing as $identity => $candidate) {
                if (! isset($seen[$identity]) && $candidate->remote_present) {
                    $candidate->remote_present = false;
                    $candidate->revision++;
                    $candidate->save();
                }
            }

            $lockedEnvironment->sync_revision++;
            $lockedEnvironment->last_pulled_at = now();
            $lockedEnvironment->save();
            $this->auditLogger->record('sync-remote', [
                'environment_id' => $environment->id,
                'environment_name' => $environment->name,
                'values' => count($values),
                'mode' => 'reconciliation',
            ]);

            return count($values);
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function page(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;
        $filters = $this->filters($filters);
        $rows = $this->rows($filters['environment_id']);
        $filtered = $this->filterRows($rows, $filters);
        $actionable = $filtered->where('actionable', true)->values();
        $lastPage = max(1, (int) ceil($filtered->count() / $perPage));
        $page = min(max(1, $page), $lastPage);

        return [
            'data' => $filtered->forPage($page, $perPage)->values()->all(),
            'total' => $filtered->count(),
            'current_page' => $page,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'filters' => $filters,
            'counts' => $rows->countBy('state')->all(),
            'locales' => $this->locales->sortLocales($rows->pluck('locale')->all()),
            'actionable_count' => $actionable->count(),
            'selection_token' => $this->selectionToken($actionable),
        ];
    }

    public function unresolvedCount(?int $environmentId = null): int
    {
        return $this->rows($environmentId)->where('needs_review', true)->count();
    }

    public function unpublishedCount(?int $environmentId = null): int
    {
        $loaded = [];

        return $this->rows($environmentId)->filter(function (array $row) use (&$loaded): bool {
            if ($row['local_value'] === null) {
                return false;
            }

            $key = $row['key'];

            if ($row['group'] === 'json') {
                $namespace = null;

                if (str_contains($key, '::')) {
                    [$namespace, $key] = explode('::', $key, 2);
                }

                $path = $this->files->jsonPath($row['locale'], $namespace);
                $loaded[$path] ??= $this->files->loadJson($row['locale'], $namespace);
                $published = $loaded[$path][$key] ?? null;
            } else {
                $path = $this->files->groupPath($row['locale'], $row['group']);
                $loaded[$path] ??= $this->files->loadGroup($row['locale'], $row['group']);
                $published = array_key_exists($key, $loaded[$path]) ? $loaded[$path][$key] : Arr::get($loaded[$path], $key);
            }

            return $row['pending_publish'] || $published !== $row['local_value'];
        })->count();
    }

    /**
     * @param  array<string, mixed>  $decision
     */
    public function resolve(array $decision): int
    {
        return DB::connection(config('vox.database.connection', 'vox'))->transaction(function () use ($decision): int {
            VoxEnvironment::query()->orderBy('id')->lockForUpdate()->get();
            $filters = $this->filters($decision['filters'] ?? []);
            $rows = $this->rows($filters['environment_id'], true);

            if ($decision['all_matching'] ?? false) {
                $selected = $this->filterRows($rows, $filters)->where('actionable', true)->values();

                if (! hash_equals($this->selectionToken($selected), (string) ($decision['selection_token'] ?? ''))) {
                    $this->stale();
                }
            } else {
                $entries = collect($decision['entries'] ?? []);
                $selected = $rows->whereIn('id', $entries->pluck('id'))->values();

                if ($entries->isEmpty() || $selected->count() !== $entries->count()) {
                    $this->stale();
                }

                $tokens = $entries->pluck('token', 'id');

                foreach ($selected as $row) {
                    if (! $row['actionable'] || ! hash_equals($row['token'], (string) $tokens->get($row['id']))) {
                        $this->stale();
                    }
                }
            }

            $action = $decision['action'] ?? '';

            if (! in_array($action, ['accept', 'keep', 'edit'], true) || $selected->isEmpty()) {
                throw ValidationException::withMessages(['reconciliation' => 'Select changes and a review decision.']);
            }

            if ($action === 'edit' && ($selected->count() !== 1 || ! is_string($decision['value'] ?? null))) {
                throw ValidationException::withMessages(['reconciliation' => 'Edit one translation value at a time.']);
            }

            if ($action !== 'keep' && $selected->contains('locale_available', false)) {
                throw ValidationException::withMessages(['reconciliation' => 'Configure the selected languages locally before accepting their values.']);
            }

            if ($action === 'accept') {
                $identities = $selected->groupBy(fn (array $row): string => RemoteTranslationSnapshot::identity($row['group'], $row['key'], $row['locale']));

                if ($identities->contains(fn (Collection $values): bool => $values->pluck('remote_value')->unique()->count() > 1)) {
                    throw ValidationException::withMessages(['reconciliation' => 'The selected environments disagree. Filter to one environment before accepting these values.']);
                }
            }

            $candidates = VoxRemoteTranslation::query()->whereIntegerInRaw('id', $selected->pluck('id')->all())->get()->keyBy('id');
            $createdTranslations = [];

            foreach ($selected as $row) {
                $localValue = $row['local_value'];

                if ($action !== 'keep') {
                    $localValue = $action === 'edit' ? $decision['value'] : $row['remote_value'];
                    $identity = RemoteTranslationSnapshot::identity($row['group'], $row['key']);
                    $translation = $row['translation_id'] !== null
                        ? VoxTranslation::query()->findOrFail($row['translation_id'])
                        : ($createdTranslations[$identity] ?? null);

                    if ($translation === null) {
                        $translation = VoxTranslation::query()->firstOrCreate(
                            ['group' => $row['group'], 'key' => $row['key']],
                            ['status' => 'pending', 'is_orphan' => false, 'is_frontend' => false, 'source' => 'remote']
                        );

                        if (! $translation->wasRecentlyCreated) {
                            $this->stale();
                        }

                        $createdTranslations[$identity] = $translation;
                    }
                    if ($translation->is_ignored) {
                        throw ValidationException::withMessages(['reconciliation' => 'Restore ignored keys before accepting remote values.']);
                    }

                    VoxTranslationValue::query()->updateOrCreate(
                        ['translation_id' => $translation->id, 'locale' => $row['locale']],
                        ['value' => $localValue, 'is_obsolete' => false, 'is_pending_publish' => true]
                    );
                    $translation->status = 'pending';
                    $translation->touch();
                }

                $candidate = $candidates->get($row['id']);
                $candidate->base_value = $row['remote_value'];
                $candidate->base_local_value = $localValue;
                $candidate->has_baseline = true;
                $candidate->revision++;
                $candidate->save();
            }

            $this->auditLogger->record('remote-reconciliation', [
                'decision' => $action,
                'values' => $selected->count(),
                'candidate_ids' => $selected->pluck('id')->all(),
                'environment_ids' => $selected->pluck('environment_id')->unique()->values()->all(),
            ]);

            return $selected->count();
        });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(?int $environmentId = null, bool $lock = false): Collection
    {
        $query = VoxRemoteTranslation::query()->orderBy('id');

        if ($environmentId !== null) {
            $query->where('environment_id', $environmentId);
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        $candidates = $query->get();
        $local = $this->localTranslations($candidates->pluck('key')->all(), $lock);
        $locales = $this->locales->resolveLocales();
        $locales[] = $this->locales->resolveBaseLocale($locales);

        return $candidates->map(function (VoxRemoteTranslation $candidate) use ($local, $locales): array {
            $translation = $local[RemoteTranslationSnapshot::identity($candidate->group, $candidate->key)] ?? null;
            $value = $translation?->values->firstWhere('locale', $candidate->locale);
            $localValue = $value?->value;
            $state = $this->state($candidate, $localValue);
            $row = [
                'id' => $candidate->id,
                'environment_id' => $candidate->environment_id,
                'group' => $candidate->group,
                'key' => $candidate->key,
                'locale' => $candidate->locale,
                'local_value' => $localValue,
                'remote_value' => $candidate->remote_value,
                'last_seen_value' => $candidate->last_seen_value,
                'base_value' => $candidate->base_value,
                'base_local_value' => $candidate->base_local_value,
                'has_baseline' => $candidate->has_baseline,
                'state' => $state,
                'needs_review' => in_array($state, ['incoming', 'conflict'], true),
                'actionable' => ! in_array($state, ['reconciled', 'unavailable'], true),
                'locale_available' => in_array($candidate->locale, $locales, true),
                'translation_id' => $translation?->id,
                'is_orphan' => $translation?->is_orphan ?? false,
                'pending_publish' => $value?->is_pending_publish ?? false,
                'revision' => $candidate->revision,
            ];
            $row['token'] = $this->token([$row, $translation?->status, $value?->is_obsolete, $value?->is_pending_publish]);

            return $row;
        });
    }

    private function state(VoxRemoteTranslation $candidate, ?string $local): string
    {
        if (! $candidate->remote_present) {
            return 'unavailable';
        }

        if ($local === $candidate->remote_value) {
            return 'reconciled';
        }

        if (! $candidate->has_baseline) {
            return $local === null ? 'incoming' : 'conflict';
        }

        if ($candidate->remote_value !== $candidate->base_value) {
            return $local !== $candidate->base_local_value ? 'conflict' : 'incoming';
        }

        return 'outgoing';
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, VoxTranslation>
     */
    private function localTranslations(array $keys, bool $lock): array
    {
        $translations = [];

        foreach (array_chunk(array_values(array_unique($keys)), 500) as $chunk) {
            $query = VoxTranslation::query()->whereIn('key', $chunk)->orderBy('id')
                ->with(['values' => function (HasMany $query) use ($lock): void {
                    if ($lock) {
                        $query->lockForUpdate();
                    }
                }]);

            if ($lock) {
                $query->lockForUpdate();
            }

            foreach ($query->get() as $translation) {
                $translations[RemoteTranslationSnapshot::identity($translation->group, $translation->key)] = $translation;
            }
        }

        return $translations;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{environment_id: int|null, state: string, locale: string, search: string}
     */
    private function filters(array $filters): array
    {
        return [
            'environment_id' => ! empty($filters['environment_id']) ? (int) $filters['environment_id'] : null,
            'state' => in_array($filters['state'] ?? '', ['all', 'incoming', 'outgoing', 'conflict', 'reconciled', 'unavailable'], true)
                ? $filters['state'] : 'review',
            'locale' => (string) ($filters['locale'] ?? ''),
            'search' => trim((string) ($filters['search'] ?? '')),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function filterRows(Collection $rows, array $filters): Collection
    {
        return $rows->filter(function (array $row) use ($filters): bool {
            if ($filters['state'] === 'review' && ! $row['needs_review']) {
                return false;
            }

            if (! in_array($filters['state'], ['review', 'all'], true) && $row['state'] !== $filters['state']) {
                return false;
            }

            if ($filters['locale'] !== '' && $row['locale'] !== $filters['locale']) {
                return false;
            }

            $haystack = implode(' ', [$row['group'], $row['key'], $row['local_value'], $row['remote_value']]);

            return $filters['search'] === '' || mb_stripos($haystack, $filters['search']) !== false;
        })->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function selectionToken(Collection $rows): string
    {
        return $this->token($rows->pluck('token')->all());
    }

    private function token(array $value): string
    {
        return hash_hmac('sha256', json_encode($value, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function stale(): never
    {
        throw ValidationException::withMessages([
            'reconciliation' => 'Translations changed since this review was loaded. Refresh and review the latest values before applying your decision.',
        ]);
    }
}
