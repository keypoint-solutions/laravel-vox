<?php

namespace KeypointSolutions\LaravelVox\Translation;

use Generator;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
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
use KeypointSolutions\LaravelVox\Support\VoxMutationLock;

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

            $seen = [];
            $changed = 0;

            foreach (array_chunk($values, 500) as $batch) {
                $identities = array_map(
                    fn (array $value): string => RemoteTranslationSnapshot::identity($value['group'], $value['key'], $value['locale']),
                    $batch
                );
                $existing = VoxRemoteTranslation::query()->where('environment_id', $environment->id)
                    ->whereIn('identity', $identities)->orderBy('id')->lockForUpdate()->get()->keyBy('identity');
                $local = $this->localTranslations(array_column($batch, 'key'), true);

                foreach ($batch as $value) {
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

                    if ($candidate->exists && $candidate->remote_value !== $value['value']) {
                        $candidate->last_seen_value = $candidate->remote_value;
                    }
                    $candidate->remote_value = $value['value'];
                    $candidate->remote_present = true;

                    if ($localValue === $value['value']) {
                        $candidate->base_value = $localValue;
                        $candidate->base_local_value = $localValue;
                        $candidate->has_baseline = true;
                    }

                    if (! $candidate->exists || $candidate->isDirty()) {
                        $candidate->revision++;
                        $candidate->save();
                        $changed++;
                    }
                    $seen[$identity] = true;
                }
            }

            $remaining = VoxRemoteTranslation::query()->where('environment_id', $environment->id)
                ->where('remote_present', true)->lockForUpdate()->lazyById(500);
            foreach ($remaining as $candidate) {
                if (! isset($seen[$candidate->identity])) {
                    $candidate->remote_present = false;
                    $candidate->revision++;
                    $candidate->save();
                    $changed++;
                }
            }

            $lockedEnvironment->sync_revision++;
            $lockedEnvironment->last_pulled_at = now();
            $lockedEnvironment->save();
            $this->auditLogger->record('sync-remote', [
                'environment_id' => $environment->id,
                'environment_name' => $environment->name,
                'values' => count($values),
                'checked' => count($values),
                'changed' => $changed,
                'mode' => 'reconciliation',
            ]);

            return count($values);
        });
    }

    public function ingestFileValue(VoxTranslation $translation, string $locale, string $value, ?string $baseline): void
    {
        $candidate = VoxRemoteTranslation::query()->whereNull('environment_id')->firstOrNew([
            'identity' => RemoteTranslationSnapshot::identity($translation->group, $translation->key, $locale),
        ]);
        if ($candidate->exists && $candidate->remote_present && $candidate->remote_value === $value) {
            return;
        }
        if (! $candidate->exists) {
            $candidate->fill([
                'environment_id' => null,
                'group' => $translation->group ?? 'json',
                'key' => $translation->key,
                'locale' => $locale,
                'base_value' => $baseline,
                'base_local_value' => $baseline,
                'has_baseline' => $baseline !== null,
                'revision' => 0,
            ]);
        }
        $candidate->last_seen_value = $candidate->remote_value;
        $candidate->remote_value = $value;
        $candidate->remote_present = true;
        $candidate->revision++;
        $candidate->save();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function page(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;
        $filters = $this->filters($filters);
        $availableLocales = $this->locales->resolveLocales();
        $defaultLocale = $this->locales->resolveBaseLocale($availableLocales);
        $availableLocales[] = $defaultLocale;
        $query = $this->reviewQuery($filters['environment_id']);
        $counts = (clone $query)->select('state')->selectRaw('COUNT(*) as aggregate')
            ->groupBy('state')->pluck('aggregate', 'state')->map(fn ($count): int => (int) $count)->all();
        $locales = (clone $query)->distinct()->pluck('locale')->all();
        $filtered = $this->filterQuery(clone $query, $filters);
        $total = (clone $filtered)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);
        $data = (clone $filtered)->orderBy('id')->forPage($page, $perPage)->get()
            ->map(fn (object $record): array => $this->reviewRow($record, $availableLocales, $defaultLocale))->all();
        $actionableTokens = [];
        foreach ((clone $filtered)->whereIn('state', ['incoming', 'conflict', 'outgoing'])->orderBy('id')->cursor() as $record) {
            $actionableTokens[] = $this->reviewRow($record, $availableLocales, $defaultLocale)['token'];
        }

        return [
            'data' => $data,
            'total' => $total,
            'current_page' => $page,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'filters' => $filters,
            'counts' => $counts,
            'locales' => $this->locales->sortLocales($locales),
            'actionable_count' => count($actionableTokens),
            'selection_token' => $this->token($actionableTokens),
        ];
    }

    public function unresolvedCount(?int $environmentId = null): int
    {
        return $this->reviewQuery($environmentId)->whereIn('state', ['incoming', 'conflict'])->count();
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
     * @return array<string, mixed>
     */
    public function reviewCandidate(int $id, string $token): array
    {
        return $this->reviewCandidates([['id' => $id, 'token' => $token]])[$id];
    }

    /** @return array<int, array<string, mixed>> */
    public function reviewCandidates(array $entries): array
    {
        $rows = $this->rows(ids: array_column($entries, 'id'))->keyBy('id');
        $selected = [];
        foreach ($entries as $entry) {
            $row = $rows->get($entry['id']);
            if ($row === null || ! $row['actionable'] || ! hash_equals($row['token'], $entry['token'])) {
                $this->stale();
            }
            $selected[$row['id']] = $row;
        }

        return $selected;
    }

    /**
     * @param  array<string, mixed>  $decision
     */
    public function resolve(array $decision): int
    {
        return app(VoxMutationLock::class)->run(fn (): int => app(TranslationFileTransaction::class)->run(fn (): int => DB::connection(config('vox.database.connection', 'vox'))->transaction(function () use ($decision): int {
            VoxEnvironment::query()->orderBy('id')->lockForUpdate()->get();
            $filters = $this->filters($decision['filters'] ?? []);
            $rows = $this->rows($filters['environment_id'], true, ($decision['all_matching'] ?? false) ? null : array_column($decision['entries'] ?? [], 'id'));

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

            if (! in_array($action, ['accept', 'keep', 'edit', 'confirm'], true) || $selected->isEmpty()) {
                throw ValidationException::withMessages(['reconciliation' => 'Select changes and a review decision.']);
            }

            $confirmations = collect($decision['entries'] ?? [])->keyBy('id');
            if ($action === 'confirm') {
                if (($decision['all_matching'] ?? false) || ($decision['publish'] ?? false) || $selected->count() > 100) {
                    throw ValidationException::withMessages(['reconciliation' => 'Confirm explicit selections from the current page without publishing.']);
                }
                $identities = $selected->map(fn (array $row): string => RemoteTranslationSnapshot::identity($row['group'], $row['key'], $row['locale']));
                if ($identities->unique()->count() !== $selected->count()) {
                    throw ValidationException::withMessages(['reconciliation' => 'Select one source per translation before confirming.']);
                }
                foreach ($selected as $row) {
                    $entry = $confirmations->get($row['id']);
                    if (! in_array($entry['side'] ?? null, ['local', 'incoming'], true) || ! array_key_exists('value', $entry)) {
                        throw ValidationException::withMessages(['reconciliation' => 'Choose wording for every selected row.']);
                    }
                }
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
            $acceptedValueIds = [];

            $decisionCounts = ['accept' => 0, 'keep' => 0, 'edit' => 0];
            foreach ($selected as $row) {
                $rowAction = $action;
                $editedValue = $decision['value'] ?? '';
                if ($action === 'confirm') {
                    $entry = $confirmations->get($row['id']);
                    $editedValue = $entry['value'] ?? '';
                    $original = $entry['side'] === 'local' ? ($row['local_value'] ?? '') : $row['remote_value'];
                    $rowAction = $editedValue !== $original ? 'edit' : ($entry['side'] === 'local' ? 'keep' : 'accept');
                }
                $decisionCounts[$rowAction]++;
                $localValue = $row['local_value'];

                if ($rowAction !== 'keep') {
                    $localValue = $rowAction === 'edit' ? $editedValue : $row['remote_value'];
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
                    if ($translation->is_pending_delete) {
                        throw ValidationException::withMessages(['reconciliation' => 'Cancel deletion before accepting remote values.']);
                    }

                    $accepted = VoxTranslationValue::query()->firstOrNew([
                        'translation_id' => $translation->id,
                        'locale' => $row['locale'],
                    ]);
                    $accepted->saveDraft($localValue, approved: true);
                    $acceptedValueIds[] = $accepted->id;
                }

                $candidate = $candidates->get($row['id']);
                $candidate->base_value = $row['remote_value'];
                $candidate->base_local_value = $localValue;
                $candidate->has_baseline = true;
                $candidate->revision++;
                $candidate->save();
            }

            if (($decision['publish'] ?? false) && $acceptedValueIds !== []) {
                app(TranslationPublisher::class)->publish($acceptedValueIds);
            }

            $this->auditLogger->record('remote-reconciliation', [
                'decision' => $action,
                'decisions' => $decisionCounts,
                'values' => $selected->count(),
                'candidate_ids' => $selected->pluck('id')->all(),
                'environment_ids' => $selected->pluck('environment_id')->unique()->values()->all(),
            ]);

            return $selected->count();
        })));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(?int $environmentId = null, bool $lock = false, ?array $ids = null): Collection
    {
        $rows = collect();
        foreach ($this->rowBatches($environmentId, $lock, $ids) as $batch) {
            $rows = $rows->concat($batch);
        }

        return $rows;
    }

    /**
     * @return Generator<int, Collection<int, array<string, mixed>>>
     */
    private function rowBatches(?int $environmentId = null, bool $lock = false, ?array $ids = null): Generator
    {
        $query = VoxRemoteTranslation::query()->orderBy('id');
        if ($ids !== null) {
            $query->whereIntegerInRaw('id', $ids);
        }

        if ($environmentId === -1) {
            $query->whereNull('environment_id');
        } elseif ($environmentId !== null) {
            $query->where('environment_id', $environmentId);
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        $locales = $this->locales->resolveLocales();
        $defaultLocale = $this->locales->resolveBaseLocale($locales);
        $locales[] = $defaultLocale;

        foreach ($query->lazyById(500)->chunk(500) as $chunk) {
            $candidates = collect($chunk->all());
            $local = $this->localTranslations($candidates->pluck('key')->all(), $lock);

            yield $candidates->map(function (VoxRemoteTranslation $candidate) use ($local, $locales, $defaultLocale): array {
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
                    'default_locale' => $defaultLocale,
                    'default_value' => $translation?->values->firstWhere('locale', $defaultLocale)?->value,
                    'remote_value' => $candidate->remote_value,
                    'last_seen_value' => $candidate->last_seen_value,
                    'base_value' => $candidate->base_value,
                    'base_local_value' => $candidate->base_local_value,
                    'has_baseline' => $candidate->has_baseline,
                    'state' => $state,
                    'needs_review' => in_array($state, ['incoming', 'conflict'], true),
                    'actionable' => ! in_array($state, ['kept', 'reconciled', 'unavailable'], true),
                    'locale_available' => in_array($candidate->locale, $locales, true),
                    'translation_id' => $translation?->id,
                    'is_orphan' => $translation?->is_orphan ?? false,
                    'pending_publish' => $value?->is_pending_publish ?? false,
                    'revision' => $candidate->revision,
                ];
                $row['token'] = $this->token([$row, $translation?->status, $value?->is_obsolete, $value?->is_pending_publish, $value?->is_approved, $value?->file_value, $value?->published_override]);

                return $row;
            });
        }
    }

    /**
     * Read comparisons without hydrating candidates or unrelated language values.
     */
    private function reviewQuery(?int $environmentId): Builder
    {
        $connection = DB::connection(config('vox.database.connection', 'vox'));
        $grammar = $connection->getQueryGrammar();
        $equal = function (string $left, string $right) use ($connection, $grammar): string {
            $left = $grammar->wrap($left);
            $right = $grammar->wrap($right);
            $comparison = match ($connection->getDriverName()) {
                'mysql', 'mariadb' => "CAST($left AS BINARY) = CAST($right AS BINARY)",
                'pgsql' => "$left COLLATE \"C\" = $right COLLATE \"C\"",
                default => "$left COLLATE BINARY = $right COLLATE BINARY",
            };

            return "(COALESCE(($comparison), FALSE) OR ($left IS NULL AND $right IS NULL))";
        };
        $sameRemote = $equal('v.value', 'r.remote_value');
        $sameBase = $equal('r.remote_value', 'r.base_value');
        $sameLocalBase = $equal('v.value', 'r.base_local_value');
        $defaultLocale = $this->locales->resolveBaseLocale($this->locales->resolveLocales());
        $query = $connection->table('vox_remote_translations as r')
            ->leftJoin('vox_translations as t', function (JoinClause $join) use ($equal): void {
                $join->on('t.key', '=', 'r.key')->whereRaw($equal('t.key', 'r.key'))
                    ->where(function (Builder $query) use ($equal): void {
                        $query->whereRaw($equal('t.group', 'r.group'))
                            ->orWhere(function (Builder $query): void {
                                $query->whereNull('t.group')->where('r.group', 'json');
                            });
                    })
                    ->where('t.id', '=', function (Builder $query) use ($equal): void {
                        $query->from('vox_translations as latest')->selectRaw('MAX(latest.id)')
                            ->whereColumn('latest.key', 'r.key')->whereRaw($equal('latest.key', 'r.key'))
                            ->where(function (Builder $query) use ($equal): void {
                                $query->whereRaw($equal('latest.group', 'r.group'))
                                    ->orWhere(function (Builder $query): void {
                                        $query->whereNull('latest.group')->where('r.group', 'json');
                                    });
                            });
                    });
            })
            ->leftJoin('vox_translation_values as v', function (JoinClause $join) use ($equal): void {
                $join->on('v.translation_id', '=', 't.id')->on('v.locale', '=', 'r.locale')
                    ->whereRaw($equal('v.locale', 'r.locale'));
            })
            ->leftJoin('vox_translation_values as d', function (JoinClause $join) use ($defaultLocale): void {
                $join->on('d.translation_id', '=', 't.id')->where('d.locale', $defaultLocale);
            })
            ->select([
                'r.*', 'v.value as local_value', 'd.value as default_value', 't.id as translation_id',
                't.is_orphan', 't.status', 'v.is_obsolete', 'v.is_pending_publish', 'v.is_approved',
                'v.file_value', 'v.published_override',
            ])
            ->selectRaw("CASE
                WHEN r.remote_present = FALSE THEN 'unavailable'
                WHEN $sameRemote THEN 'reconciled'
                WHEN r.has_baseline = FALSE THEN CASE WHEN v.value IS NULL THEN 'incoming' ELSE 'conflict' END
                WHEN NOT $sameBase THEN CASE WHEN $sameLocalBase THEN 'incoming' ELSE 'conflict' END
                WHEN $sameLocalBase THEN 'kept'
                ELSE 'outgoing' END as state");
        if ($environmentId === -1) {
            $query->whereNull('r.environment_id');
        } elseif ($environmentId !== null) {
            $query->where('r.environment_id', $environmentId);
        }

        return $connection->query()->fromSub($query, 'review');
    }

    private function filterQuery(Builder $query, array $filters): Builder
    {
        if ($filters['state'] === 'review') {
            $query->whereIn('state', ['incoming', 'conflict']);
        } elseif ($filters['state'] !== 'all') {
            $query->where('state', $filters['state']);
        }
        if ($filters['locale'] !== '') {
            $query->where('locale', $filters['locale']);
        }
        if ($filters['search'] !== '') {
            // Preserve Unicode case folding and literal substring matching across database drivers.
            $ids = [];
            foreach ((clone $query)->select(['id', 'group', 'key', 'local_value', 'remote_value'])->cursor() as $row) {
                if (mb_stripos(implode(' ', [$row->group, $row->key, $row->local_value, $row->remote_value]), $filters['search']) !== false) {
                    $ids[] = (int) $row->id;
                }
            }
            $query->whereIntegerInRaw('id', $ids);
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function reviewRow(object $record, array $locales, string $defaultLocale): array
    {
        $row = [
            'id' => (int) $record->id,
            'environment_id' => $record->environment_id === null ? null : (int) $record->environment_id,
            'group' => $record->group,
            'key' => $record->key,
            'locale' => $record->locale,
            'local_value' => $record->local_value,
            'default_locale' => $defaultLocale,
            'default_value' => $record->default_value,
            'remote_value' => $record->remote_value,
            'last_seen_value' => $record->last_seen_value,
            'base_value' => $record->base_value,
            'base_local_value' => $record->base_local_value,
            'has_baseline' => (bool) $record->has_baseline,
            'state' => $record->state,
            'needs_review' => in_array($record->state, ['incoming', 'conflict'], true),
            'actionable' => in_array($record->state, ['incoming', 'conflict', 'outgoing'], true),
            'locale_available' => in_array($record->locale, $locales, true),
            'translation_id' => $record->translation_id === null ? null : (int) $record->translation_id,
            'is_orphan' => (bool) $record->is_orphan,
            'pending_publish' => (bool) $record->is_pending_publish,
            'revision' => (int) $record->revision,
        ];
        $row['token'] = $this->token([$row, $record->status,
            $record->is_obsolete === null ? null : (bool) $record->is_obsolete,
            $record->is_pending_publish === null ? null : (bool) $record->is_pending_publish,
            $record->is_approved === null ? null : (bool) $record->is_approved,
            $record->file_value, $record->published_override]);

        return $row;
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

        return $local === $candidate->base_local_value ? 'kept' : 'outgoing';
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
            'state' => in_array($filters['state'] ?? '', ['all', 'incoming', 'outgoing', 'kept', 'conflict', 'reconciled', 'unavailable'], true)
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
