<script setup lang="ts">
    import { router, usePage } from '@inertiajs/vue3';
    import { computed, ref, watch } from 'vue';

    import { Badge, Button, Checkbox, Input, Label, Select, SlidePanel, Textarea } from '@/components/ui';
    import PageSizeSelect from '@/components/ui/PageSizeSelect.vue';

    interface Candidate {
        id: number;
        environment_id: number | null;
        group: string;
        key: string;
        locale: string;
        local_value: string | null;
        remote_value: string;
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
    const message = ref('');
    const error = ref('');
    const editing = ref<Candidate | null>(null);
    const editedValue = ref('');
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
        { value: 'outgoing', label: 'Local values kept / changed' },
        { value: 'reconciled', label: 'Matching values' },
        { value: 'unavailable', label: 'No longer in source' },
        { value: 'all', label: 'All values' },
    ];
    const labels: Record<string, string> = {
        incoming: 'Incoming',
        conflict: 'Conflict',
        outgoing: 'Local value kept / changed',
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

    function edit(row: Candidate): void {
        editing.value = row;
        editedValue.value = row.local_value ?? row.remote_value;
        error.value = '';
    }

    function decide(action: 'accept' | 'keep' | 'edit', row?: Candidate, publish = false): void {
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
                publish,
                all_matching: !row && allMatching.value,
                entries,
                filters: props.review.filters,
                selection_token: props.review.selection_token,
                ...(action === 'edit' ? { value: editedValue.value } : {}),
            },
            {
                preserveScroll: true,
                onSuccess: (response) => {
                    message.value = (response.flash?.success as string | undefined) ?? 'Review decisions saved.';
                    clearSelection();
                    editing.value = null;
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
                Compare incoming wording with the current database value. Accept approves the selected translations;
                Accept and publish also writes only those translations to language files. Keep local retains the
                database wording.
            </p>
            <div class="flex flex-wrap gap-2 text-xs">
                <Badge variant="secondary">{{ review.counts.incoming ?? 0 }} incoming</Badge>
                <Badge variant="outline">{{ review.counts.conflict ?? 0 }} conflicts</Badge>
                <Badge variant="secondary">{{ review.counts.outgoing ?? 0 }} local values kept / changed</Badge>
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
                    variant="outline"
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
            <div class="flex flex-wrap gap-2">
                <Button
                    data-test="review-bulk-accept"
                    :disabled="busy || filtersDirty || selectedCount === 0"
                    @click="decide('accept')"
                >
                    {{ busy ? 'Saving…' : `Accept (${selectedCount})` }}
                </Button>
                <Button
                    data-test="review-bulk-accept-publish"
                    variant="outline"
                    :disabled="busy || filtersDirty || selectedCount === 0"
                    @click="decide('accept', undefined, true)"
                >
                    Accept and publish ({{ selectedCount }})
                </Button>
                <Button
                    data-test="review-bulk-keep"
                    variant="outline"
                    :disabled="busy || filtersDirty || selectedCount === 0"
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
            <div class="grid gap-4 md:grid-cols-2">
                <div class="min-w-0 rounded-lg border p-3">
                    <p class="text-muted-foreground mb-2 text-xs font-medium">Current database wording</p>
                    <pre
                        class="max-h-40 overflow-auto font-sans text-sm [overflow-wrap:anywhere] whitespace-pre-wrap"
                        >{{ row.local_value ?? 'No local value' }}</pre>
                </div>
                <div class="min-w-0 rounded-lg border p-3">
                    <p class="text-muted-foreground mb-2 text-xs font-medium">Incoming wording</p>
                    <pre
                        class="max-h-40 overflow-auto font-sans text-sm [overflow-wrap:anywhere] whitespace-pre-wrap"
                        >{{ row.remote_value }}</pre>
                </div>
            </div>
            <details class="text-muted-foreground text-xs">
                <summary class="cursor-pointer">Comparison history</summary>
                <div class="mt-2 space-y-2">
                    <template v-if="row.has_baseline">
                        <p>Source value at last agreement or review:</p>
                        <pre class="max-h-32 overflow-auto font-sans [overflow-wrap:anywhere] whitespace-pre-wrap">{{
                            row.base_value ?? 'No value'
                        }}</pre>
                        <p>Local value at that time:</p>
                        <pre class="max-h-32 overflow-auto font-sans [overflow-wrap:anywhere] whitespace-pre-wrap">{{
                            row.base_local_value ?? 'No value'
                        }}</pre>
                    </template>
                    <p v-else>No shared baseline yet. Existing values that differ need an explicit review decision.</p>
                    <p>Previously seen source value:</p>
                    <pre class="max-h-32 overflow-auto font-sans [overflow-wrap:anywhere] whitespace-pre-wrap">{{
                        row.last_seen_value ?? 'First observation'
                    }}</pre>
                </div>
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
                class="flex flex-wrap gap-2"
            >
                <Button
                    size="sm"
                    :disabled="busy || !row.locale_available"
                    @click="decide('accept', row)"
                    >Accept</Button
                >
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="busy || !row.locale_available || row.is_orphan"
                    @click="decide('accept', row, true)"
                    >Accept and publish</Button
                >
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="busy"
                    @click="decide('keep', row)"
                    >Keep local</Button
                >
                <Button
                    size="sm"
                    variant="ghost"
                    :disabled="busy || !row.locale_available"
                    @click="edit(row)"
                    >Edit merged value</Button
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

    <SlidePanel
        :open="editing !== null"
        title="Edit merged value"
        :subtitle="editing ? `${editing.group}.${editing.key} · ${editing.locale}` : ''"
        @close="editing = null"
    >
        <form
            v-if="editing"
            id="review-edit-form"
            class="space-y-4"
            @submit.prevent="decide('edit', editing)"
        >
            <p class="text-muted-foreground text-sm">
                Save and approve the wording you want to keep. You can publish it when you are ready.
            </p>
            <Label for="review-edited-value">Merged translation</Label>
            <Textarea
                id="review-edited-value"
                v-model="editedValue"
                class="min-h-48"
                :disabled="busy"
            />
            <p
                v-if="error"
                role="alert"
                class="text-destructive text-sm"
            >
                {{ error }}
            </p>
        </form>
        <template #footer>
            <Button
                type="submit"
                form="review-edit-form"
                :disabled="busy"
                >Save merged value</Button
            >
        </template>
    </SlidePanel>
</template>
