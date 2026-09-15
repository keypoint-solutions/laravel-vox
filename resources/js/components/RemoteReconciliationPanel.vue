<script setup lang="ts">
    import { router, usePage } from '@inertiajs/vue3';
    import { Sparkles } from '@lucide/vue';
    import { computed, ref, watch } from 'vue';

    import { Badge, Button, Checkbox, Input, Label, Select, Textarea } from '@/components/ui';
    import FontAwesomeCheck from '@/components/ui/FontAwesomeCheck.vue';
    import PageSizeSelect from '@/components/ui/PageSizeSelect.vue';

    interface Candidate {
        id: number;
        environment_id: number | null;
        group: string;
        key: string;
        locale: string;
        local_value: string | null;
        remote_value: string;
        default_locale: string;
        default_value: string | null;
        base_value: string | null;
        base_local_value: string | null;
        last_seen_value: string | null;
        has_baseline: boolean;
        state: string;
        actionable: boolean;
        locale_available: boolean;
        is_orphan: boolean;
        token: string;
    }

    export interface ReconciliationPage {
        data: Candidate[];
        total: number;
        current_page: number;
        last_page: number;
        per_page: number;
        filters: { environment_id: number | null; state: string; locale: string; search: string };
        counts: Record<string, number>;
        locales: string[];
        actionable_count: number;
        selection_token: string;
    }

    const props = defineProps<{
        review: ReconciliationPage;
        canChooseWithAi?: boolean;
        environments: { id: number; name: string }[];
    }>();
    const page = usePage();
    const environment = ref(String(props.review.filters.environment_id ?? ''));
    const state = ref(props.review.filters.state);
    const locale = ref(props.review.filters.locale);
    const search = ref(props.review.filters.search);
    const selected = ref<Record<number, string>>({});
    const allMatching = ref(false);
    const busy = ref(false);
    const choosingId = ref<number | null>(null);
    const aiReasons = ref<Record<number, string>>({});
    const message = ref('');
    const error = ref('');
    const choices = ref<Record<number, { token: string; side: 'local' | 'incoming'; local: string; incoming: string }>>(
        {}
    );
    const bulkHasEdits = computed(() =>
        props.review.data.some(
            (row) =>
                (allMatching.value || selected.value[row.id]) &&
                choices.value[row.id]?.token === row.token &&
                (choices.value[row.id].incoming !== row.remote_value ||
                    choices.value[row.id].local !== (row.local_value ?? ''))
        )
    );

    watch(
        () => props.review.data,
        (rows) => {
            const currentTokens = new Map(rows.map((row) => [row.id, row.token]));
            for (const [id, choice] of Object.entries(choices.value)) {
                if (currentTokens.get(Number(id)) !== choice.token) {
                    delete choices.value[Number(id)];
                    delete aiReasons.value[Number(id)];
                }
            }
        }
    );
    const visible = computed(() => props.review.data.filter((row) => row.actionable));
    const visibleSelected = computed(
        () => visible.value.length > 0 && (allMatching.value || visible.value.every((row) => selected.value[row.id]))
    );
    const selectedCount = computed(() =>
        allMatching.value ? props.review.actionable_count : Object.keys(selected.value).length
    );
    const filtersDirty = computed(
        () =>
            environment.value !== String(props.review.filters.environment_id ?? '') ||
            state.value !== props.review.filters.state ||
            locale.value !== props.review.filters.locale ||
            search.value !== props.review.filters.search
    );
    const environmentOptions = computed(() => [
        { value: '', label: 'All sources' },
        { value: '-1', label: 'Local files' },
        ...props.environments.map((item) => ({ value: String(item.id), label: item.name })),
    ]);
    const stateOptions = [
        { value: 'review', label: 'Needs review' },
        { value: 'incoming', label: 'Incoming changes' },
        { value: 'conflict', label: 'Conflicts' },
        { value: 'outgoing', label: 'Local changes' },
        { value: 'kept', label: 'Resolved: kept local' },
        { value: 'reconciled', label: 'Matching values' },
        { value: 'unavailable', label: 'No longer in source' },
        { value: 'all', label: 'All values' },
    ];
    const labels: Record<string, string> = {
        incoming: 'Incoming',
        conflict: 'Conflict',
        outgoing: 'Local change',
        kept: 'Resolved: kept local',
        reconciled: 'Matching',
        unavailable: 'No longer in source',
    };
    const localeOptions = computed(() => [
        { value: '', label: 'All languages' },
        ...props.review.locales.map((code) => ({ value: code, label: code })),
    ]);

    function clearSelection(): void {
        selected.value = {};
        allMatching.value = false;
    }

    watch(() => props.review.selection_token, clearSelection);
    watch([environment, state, locale, search], clearSelection);
    watch(
        () => JSON.stringify(props.review.filters),
        () => {
            clearSelection();
            environment.value = String(props.review.filters.environment_id ?? '');
            state.value = props.review.filters.state;
            locale.value = props.review.filters.locale;
            search.value = props.review.filters.search;
        }
    );

    function toggleVisible(checked: boolean): void {
        allMatching.value = false;
        for (const row of visible.value) {
            if (checked) {
                selected.value[row.id] = row.token;
            } else {
                delete selected.value[row.id];
            }
        }
    }

    function toggle(row: Candidate, checked: boolean): void {
        if (allMatching.value) {
            clearSelection();
            toggleVisible(true);
        }
        if (checked) {
            selected.value[row.id] = row.token;
        } else {
            delete selected.value[row.id];
        }
    }

    const perPage = ref(props.review.per_page ?? 25);

    function filter(reviewPage = 1): void {
        router.get(
            page.props.vox?.routes?.sync ?? '',
            {
                environment_id: environment.value || undefined,
                state: state.value,
                locale: locale.value || undefined,
                search: search.value || undefined,
                review_page: reviewPage,
                per_page: perPage.value,
            },
            { preserveState: true, preserveScroll: true }
        );
    }

    function choose(row: Candidate, side: 'local' | 'incoming'): void {
        if (busy.value || filtersDirty.value || !row.actionable || (side === 'incoming' && !row.locale_available)) {
            return;
        }
        delete aiReasons.value[row.id];
        const previous = choices.value[row.id];
        choices.value[row.id] = {
            token: row.token,
            side,
            local: previous?.token === row.token ? previous.local : (row.local_value ?? ''),
            incoming: previous?.token === row.token ? previous.incoming : row.remote_value,
        };
    }

    function updateWording(row: Candidate, side: 'local' | 'incoming', value: string): void {
        choose(row, side);
        if (choices.value[row.id]?.side === side) {
            choices.value[row.id][side] = value;
        }
    }

    const pageSelection = computed(() =>
        visible.value.filter(
            (row) => row.locale_available && (allMatching.value || selected.value[row.id] === row.token)
        )
    );
    const canConfirmPage = computed(
        () =>
            pageSelection.value.length > 0 &&
            pageSelection.value.every((row) => choices.value[row.id]?.token === row.token)
    );

    function chooseWithAi(row?: Candidate): void {
        if (busy.value || filtersDirty.value) return;
        const rows = row ? [row] : pageSelection.value;
        if (!rows.length || rows.some((item) => !item.actionable || !item.locale_available)) return;
        const entries = rows.map((item) => ({
            id: item.id,
            token: item.token,
            local: choices.value[item.id]?.local ?? item.local_value ?? '',
            incoming: choices.value[item.id]?.incoming ?? item.remote_value,
        }));
        busy.value = true;
        choosingId.value = row?.id ?? -1;
        error.value = '';
        router.post(page.props.vox?.routes?.sync_choose ?? '', row ? entries[0] : { entries }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (response) => {
                type Suggestion = { id: number; token: string; choice: 'local' | 'incoming'; reason: string };
                const results = row
                    ? [response.flash?.translationChoice as Suggestion]
                    : (response.flash?.translationChoices as Suggestion[]);
                for (const result of results ?? []) {
                    if (!result) continue;
                    const submitted = entries.find((item) => item.id === result.id);
                    const current = props.review.data.find((item) => item.id === result.id);
                    if (
                        !submitted ||
                        !current ||
                        result.token !== submitted.token ||
                        current.token !== submitted.token ||
                        !['local', 'incoming'].includes(result.choice) ||
                        (choices.value[current.id]?.local ?? current.local_value ?? '') !== submitted.local ||
                        (choices.value[current.id]?.incoming ?? current.remote_value) !== submitted.incoming
                    )
                        continue;
                    choices.value[current.id] = {
                        token: current.token,
                        side: result.choice,
                        local: submitted.local,
                        incoming: submitted.incoming,
                    };
                    aiReasons.value[current.id] = result.reason;
                }
            },
            onError: (errors) => {
                error.value = Object.values(errors)[0] ?? 'AI could not choose wording.';
            },
            onFinish: () => {
                busy.value = false;
                choosingId.value = null;
            },
        });
    }

    function confirmPage(): void {
        if (busy.value || filtersDirty.value || !canConfirmPage.value) return;
        const entries = pageSelection.value.map((row) => {
            const choice = choices.value[row.id];
            return { id: row.id, token: row.token, side: choice.side, value: choice[choice.side] };
        });
        busy.value = true;
        error.value = '';
        router.post(
            page.props.vox?.routes?.sync_reconcile ?? '',
            { action: 'confirm', entries, publish: false },
            {
                preserveScroll: true,
                onSuccess: (response) => {
                    message.value = (response.flash?.success as string) ?? 'Selections confirmed.';
                    for (const entry of entries) {
                        delete choices.value[entry.id];
                        delete aiReasons.value[entry.id];
                    }
                    clearSelection();
                },
                onError: (errors) => {
                    error.value = Object.values(errors)[0] ?? 'Selections could not be confirmed.';
                },
                onFinish: () => {
                    busy.value = false;
                },
            }
        );
    }

    function confirm(row: Candidate): void {
        const choice = choices.value[row.id];
        if (!choice || choice.token !== row.token || busy.value || filtersDirty.value) {
            return;
        }
        const original = choice.side === 'local' ? (row.local_value ?? '') : row.remote_value;
        const action = choice[choice.side] !== original ? 'edit' : choice.side === 'local' ? 'keep' : 'accept';
        decide(action, row);
    }

    function decide(action: 'accept' | 'keep' | 'edit', row?: Candidate): void {
        busy.value = true;
        message.value = '';
        error.value = '';
        const entries = row
            ? [{ id: row.id, token: row.token }]
            : Object.entries(selected.value).map(([id, token]) => ({ id: Number(id), token }));

        router.post(
            page.props.vox?.routes?.sync_reconcile ?? '',
            {
                action,
                publish: false,
                all_matching: !row && allMatching.value,
                entries,
                filters: props.review.filters,
                selection_token: props.review.selection_token,
                ...(action === 'edit' && row ? { value: choices.value[row.id][choices.value[row.id].side] } : {}),
            },
            {
                preserveScroll: true,
                onSuccess: (response) => {
                    message.value = (response.flash?.success as string | undefined) ?? 'Review decisions saved.';
                    clearSelection();
                    if (row) {
                        delete choices.value[row.id];
                    }
                },
                onError: (errors) => {
                    error.value = Object.values(errors)[0] ?? 'The review decision could not be saved.';
                },
                onFinish: () => {
                    busy.value = false;
                },
            }
        );
    }
</script>

<template>
    <section
        id="remote-review"
        class="bg-card rounded-xl border"
        aria-labelledby="remote-review-title"
    >
        <div class="space-y-3 border-b p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2
                    id="remote-review-title"
                    class="text-sm font-semibold"
                >
                    Incoming translations
                </h2>
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="busy"
                    @click="filter(review.current_page)"
                    >Refresh review</Button
                >
            </div>
            <p class="text-muted-foreground text-sm">
                Select the wording you want to keep, then confirm your selection. You can edit either version before
                confirming. Only the selected version is saved. Incoming or edited selections are approved and ready to
                publish when you choose.
            </p>
            <div class="flex flex-wrap gap-2 text-xs">
                <Badge variant="secondary">{{ review.counts.incoming ?? 0 }} incoming</Badge>
                <Badge variant="outline">{{ review.counts.conflict ?? 0 }} conflicts</Badge>
                <Badge variant="secondary">{{ review.counts.outgoing ?? 0 }} local changes</Badge>
                <Badge variant="secondary">{{ review.counts.kept ?? 0 }} resolved: kept local</Badge>
                <Badge variant="secondary">{{ review.counts.reconciled ?? 0 }} matching</Badge>
            </div>
            <form
                class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5"
                @submit.prevent="filter()"
            >
                <div class="space-y-1.5">
                    <Label for="review-environment">Source</Label>
                    <Select
                        id="review-environment"
                        v-model="environment"
                        :options="environmentOptions"
                        :disabled="busy"
                    />
                </div>
                <div class="space-y-1.5">
                    <Label for="review-state">Changes</Label>
                    <Select
                        id="review-state"
                        v-model="state"
                        :options="stateOptions"
                        :disabled="busy"
                    />
                </div>
                <div class="space-y-1.5">
                    <Label for="review-locale">Language</Label>
                    <Select
                        id="review-locale"
                        v-model="locale"
                        :options="localeOptions"
                        :disabled="busy"
                    />
                </div>
                <div class="space-y-1.5">
                    <Label for="review-search">Key or wording</Label>
                    <Input
                        id="review-search"
                        v-model="search"
                        :disabled="busy"
                    />
                </div>
                <Button
                    class="self-end"
                    type="submit"
                    :disabled="busy"
                    >Filter changes</Button
                >
            </form>
        </div>

        <div
            v-if="message"
            role="status"
            class="border-b bg-emerald-500/10 p-4 text-sm"
        >
            {{ message }}
        </div>
        <div
            v-if="error"
            role="alert"
            class="text-destructive border-b p-4 text-sm"
        >
            {{ error }}
        </div>

        <div
            v-if="review.actionable_count > 0"
            class="bg-muted/30 space-y-3 border-b p-4"
        >
            <p
                v-if="filtersDirty"
                class="text-muted-foreground text-sm"
            >
                Apply your filters before selecting changes.
            </p>
            <div class="flex flex-wrap items-center gap-3">
                <Checkbox
                    id="review-select-visible"
                    :model-value="visibleSelected"
                    :disabled="busy || filtersDirty"
                    @update:model-value="toggleVisible"
                />
                <Label for="review-select-visible">Select visible changes</Label>
                <span class="text-muted-foreground text-sm"
                    >{{ selectedCount }} selected{{ allMatching ? ' across all pages' : '' }}</span
                >
                <Button
                    v-if="!allMatching"
                    data-test="review-select-all"
                    size="sm"
                    variant="ghost"
                    :disabled="busy || filtersDirty"
                    @click="allMatching = true"
                >
                    Select all {{ review.actionable_count }} matching changes
                </Button>
                <Button
                    v-if="selectedCount"
                    size="sm"
                    variant="ghost"
                    :disabled="busy"
                    @click="clearSelection"
                    >Clear selection</Button
                >
            </div>
            <p
                v-if="bulkHasEdits"
                class="text-muted-foreground text-sm"
            >
                Confirm the selected wording below to save edits. Accept and Keep local use the original values.
            </p>
            <div
                v-if="canChooseWithAi"
                class="space-y-2"
            >
                <p class="text-muted-foreground text-xs">
                    AI choices and confirmation apply only to checked rows on this page. Suggestions are cleared when
                    you leave the page.
                </p>
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        size="sm"
                        variant="ghost"
                        data-test="review-bulk-ai"
                        :disabled="busy || filtersDirty || !pageSelection.length"
                        @click="chooseWithAi()"
                    >
                        <Sparkles
                            class="size-4 shrink-0"
                            aria-hidden="true"
                        />
                        {{ choosingId === -1 ? 'Choosing…' : `Choose with AI (${pageSelection.length})` }}
                    </Button>
                    <Button
                        size="sm"
                        data-test="review-bulk-confirm"
                        :disabled="busy || filtersDirty || !canConfirmPage"
                        @click="confirmPage"
                    >
                        Confirm selections ({{ pageSelection.length }})
                    </Button>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    data-test="review-bulk-accept"
                    :disabled="busy || filtersDirty || selectedCount === 0 || bulkHasEdits"
                    @click="decide('accept')"
                >
                    {{ busy ? 'Saving…' : `Accept (${selectedCount})` }}
                </Button>
                <Button
                    data-test="review-bulk-keep"
                    variant="outline"
                    :disabled="busy || filtersDirty || selectedCount === 0 || bulkHasEdits"
                    @click="decide('keep')"
                >
                    Keep local ({{ selectedCount }})
                </Button>
            </div>
        </div>

        <div
            v-if="review.data.length === 0"
            class="p-8 text-center"
        >
            <p class="text-sm font-medium">No incoming changes match these filters</p>
            <p class="text-muted-foreground mt-2 text-sm">
                Sync local files or pull an environment to compare wording, or change the filters above.
            </p>
        </div>

        <article
            v-for="row in review.data"
            :key="row.id"
            :data-test="`remote-candidate-${row.id}`"
            class="space-y-3 border-b p-5"
        >
            <div class="flex items-start gap-3">
                <Checkbox
                    v-if="row.actionable"
                    :aria-label="`Select ${row.group}.${row.key} ${row.locale}`"
                    :model-value="allMatching || Boolean(selected[row.id])"
                    :disabled="busy || filtersDirty"
                    @update:model-value="toggle(row, $event)"
                />
                <div class="min-w-0 flex-1 space-y-1">
                    <p class="text-sm font-semibold [overflow-wrap:anywhere]">
                        {{ row.group === 'json' ? row.key : `${row.group}.${row.key}` }}
                    </p>
                    <p class="text-muted-foreground text-xs">
                        {{
                            row.environment_id === null
                                ? 'Local files'
                                : environments.find((item) => item.id === row.environment_id)?.name
                        }}
                        · {{ row.locale }}
                    </p>
                </div>
                <Badge :variant="row.state === 'conflict' ? 'outline' : 'secondary'">{{ labels[row.state] }}</Badge>
            </div>
            <div
                class="grid gap-4 md:grid-cols-2"
                role="group"
                :aria-label="`Choose wording for ${row.key} ${row.locale}`"
            >
                <div
                    class="min-w-0 rounded-lg transition-colors"
                    @click="choose(row, 'local')"
                >
                    <label
                        :data-test="`review-local-control-${row.id}`"
                        :class="choices[row.id]?.side === 'local' ? 'text-emerald-700 dark:text-emerald-400' : ''"
                        class="mb-3 flex cursor-pointer items-center gap-2 text-xs font-medium"
                    >
                        <input
                            v-if="row.actionable"
                            type="radio"
                            :name="`wording-${row.id}`"
                            :data-test="`review-local-${row.id}`"
                            :checked="choices[row.id]?.side === 'local'"
                            :disabled="busy || filtersDirty"
                            class="peer sr-only"
                            @change="choose(row, 'local')"
                        />
                        <span
                            class="flex items-center gap-2 rounded-sm peer-focus-visible:outline-2 peer-focus-visible:outline-offset-4 peer-focus-visible:outline-emerald-600"
                        >
                            <FontAwesomeCheck
                                v-if="row.actionable"
                                class="size-3.5 shrink-0 text-emerald-700 dark:text-emerald-400"
                                :class="choices[row.id]?.side === 'local' ? 'opacity-100' : 'opacity-0'"
                            />
                            Current database wording
                        </span>
                    </label>
                    <Textarea
                        v-if="row.actionable && row.locale_available"
                        :model-value="choices[row.id]?.local ?? row.local_value ?? ''"
                        :aria-label="`Current wording for ${row.key} ${row.locale}`"
                        :data-test="`review-local-wording-${row.id}`"
                        :disabled="busy || filtersDirty"
                        class="border-border/60 min-h-32 resize-y transition-colors focus-visible:ring-emerald-500/40 focus-visible:ring-offset-0"
                        :class="
                            choices[row.id]?.side === 'local'
                                ? 'bg-emerald-50 dark:bg-emerald-950/40'
                                : 'bg-transparent'
                        "
                        @focus="choose(row, 'local')"
                        @update:model-value="updateWording(row, 'local', $event)"
                    />
                    <pre
                        v-else
                        class="max-h-64 overflow-auto font-sans text-sm [overflow-wrap:anywhere] whitespace-pre-wrap"
                        >{{ row.local_value ?? 'No local value' }}</pre>
                </div>
                <div
                    class="min-w-0 rounded-lg transition-colors"
                    @click="choose(row, 'incoming')"
                >
                    <label
                        :data-test="`review-incoming-control-${row.id}`"
                        :class="choices[row.id]?.side === 'incoming' ? 'text-emerald-700 dark:text-emerald-400' : ''"
                        class="mb-3 flex cursor-pointer items-center gap-2 text-xs font-medium"
                    >
                        <input
                            v-if="row.actionable"
                            type="radio"
                            :name="`wording-${row.id}`"
                            :data-test="`review-incoming-${row.id}`"
                            :checked="choices[row.id]?.side === 'incoming'"
                            :disabled="busy || filtersDirty || !row.locale_available"
                            class="peer sr-only"
                            @change="choose(row, 'incoming')"
                        />
                        <span
                            class="flex items-center gap-2 rounded-sm peer-focus-visible:outline-2 peer-focus-visible:outline-offset-4 peer-focus-visible:outline-emerald-600"
                        >
                            <FontAwesomeCheck
                                v-if="row.actionable"
                                class="size-3.5 shrink-0 text-emerald-700 dark:text-emerald-400"
                                :class="choices[row.id]?.side === 'incoming' ? 'opacity-100' : 'opacity-0'"
                            />
                            Incoming wording
                        </span>
                    </label>
                    <Textarea
                        v-if="row.actionable && row.locale_available"
                        :model-value="choices[row.id]?.incoming ?? row.remote_value"
                        :aria-label="`Incoming wording for ${row.key} ${row.locale}`"
                        :data-test="`review-wording-${row.id}`"
                        :disabled="busy || filtersDirty"
                        class="border-border/60 min-h-32 resize-y transition-colors focus-visible:ring-emerald-500/40 focus-visible:ring-offset-0"
                        :class="
                            choices[row.id]?.side === 'incoming'
                                ? 'bg-emerald-50 dark:bg-emerald-950/40'
                                : 'bg-transparent'
                        "
                        @focus="choose(row, 'incoming')"
                        @update:model-value="updateWording(row, 'incoming', $event)"
                    />
                    <pre
                        v-else
                        class="max-h-64 overflow-auto font-sans text-sm [overflow-wrap:anywhere] whitespace-pre-wrap"
                        >{{ row.remote_value }}</pre>
                </div>
            </div>
            <details
                class="text-muted-foreground text-sm"
                :data-test="`review-reference-${row.id}`"
            >
                <summary
                    class="cursor-pointer rounded-sm focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-600"
                >
                    Default-language reference · {{ row.default_locale }}
                </summary>
                <pre
                    v-if="row.default_value"
                    class="text-foreground mt-3 max-h-64 overflow-auto font-sans text-sm [overflow-wrap:anywhere] whitespace-pre-wrap"
                    >{{ row.default_value }}</pre>
                <p
                    v-else
                    class="mt-3"
                >
                    No wording is available in the default language for this key.
                </p>
            </details>
            <p
                v-if="row.state === 'unavailable'"
                class="text-muted-foreground text-xs"
            >
                This value is absent from the latest source snapshot. Local values are retained; source removals are not
                applied automatically.
            </p>
            <p
                v-if="!row.locale_available"
                class="text-muted-foreground text-xs"
            >
                Configure this language locally before accepting its values.
            </p>
            <p
                v-if="row.is_orphan"
                class="text-muted-foreground text-xs"
            >
                This local key is an orphan. Accepting wording does not make it publishable until local source or
                language files use it again.
            </p>
            <div
                v-if="row.actionable"
                class="flex flex-wrap items-center justify-end gap-2"
            >
                <p
                    v-if="aiReasons[row.id]"
                    role="status"
                    class="text-muted-foreground w-full text-sm [overflow-wrap:anywhere]"
                >
                    AI suggestion: {{ aiReasons[row.id] }} Review the selection, then confirm to save.
                </p>
                <Button
                    v-if="canChooseWithAi"
                    size="sm"
                    variant="ghost"
                    :data-test="`review-ai-${row.id}`"
                    :disabled="busy || filtersDirty || !row.locale_available"
                    @click="chooseWithAi(row)"
                >
                    <Sparkles
                        class="size-4 shrink-0"
                        aria-hidden="true"
                    />
                    {{ choosingId === row.id ? 'Choosing…' : 'Choose with AI' }}
                </Button>
                <Button
                    size="sm"
                    :data-test="`review-confirm-${row.id}`"
                    :disabled="
                        busy ||
                        filtersDirty ||
                        !choices[row.id] ||
                        (choices[row.id]?.side === 'incoming' && !row.locale_available)
                    "
                    @click="confirm(row)"
                    >Confirm selection</Button
                >
            </div>
        </article>

        <div class="flex flex-wrap items-center justify-between gap-3 p-4 text-sm">
            <p class="text-muted-foreground">
                {{ review.total }} values · Page {{ review.current_page }} of {{ review.last_page }}
            </p>
            <PageSizeSelect
                v-model="perPage"
                @update:model-value="filter(1)"
            />
            <div class="flex gap-2">
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="busy || review.current_page <= 1"
                    @click="filter(review.current_page - 1)"
                    >Previous</Button
                >
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="busy || review.current_page >= review.last_page"
                    @click="filter(review.current_page + 1)"
                    >Next</Button
                >
            </div>
        </div>
    </section>
</template>
