<script lang="ts" setup>
    import { Head, router, usePage } from '@inertiajs/vue3';
    import { Check, FileText, Plus, RotateCcw, Sparkles } from '@lucide/vue';
    import { computed, ref, watch } from 'vue';

    import CleanupPanel from '@/components/manage/CleanupPanel.vue';
    import CompactFilterBar from '@/components/manage/CompactFilterBar.vue';
    import DynamicTranslationPanel from '@/components/manage/DynamicTranslationPanel.vue';
    import FallbackSettings from '@/components/manage/FallbackSettings.vue';
    import GroupFilter from '@/components/manage/GroupFilter.vue';
    import ToastNotification from '@/components/manage/ToastNotification.vue';
    import TranslationEditPanel from '@/components/manage/TranslationEditPanel.vue';
    import TranslationRow from '@/components/manage/TranslationRow.vue';
    import type { SelectOption, ToggleOption } from '@/components/ui';
    import { Button, Checkbox, SearchInput, Select, ToggleGroup, Tooltip } from '@/components/ui';
    import PageSizeSelect from '@/components/ui/PageSizeSelect.vue';
    import PaginationNav from '@/components/ui/PaginationNav.vue';
    import { useCompactHeader } from '@/composables/useCompactHeader';
    import { useDateTime } from '@/composables/useDateTime';
    import { useManageFilters } from '@/composables/useManageFilters';
    import { useToast } from '@/composables/useToast';
    import { useVoxRoutes } from '@/composables/useVoxRoutes';
    import Layout from '@/layouts/Layout.vue';
    import { firstError, flashSuccess } from '@/lib/inertia';
    import { translationActionUrl } from '@/lib/manage';
    import type { CleanupAction, ManagePageProps, TranslationItem } from '@/types/manage';

    defineOptions({
        layout: Layout,
    });

    const page = usePage<ManagePageProps>();
    const { formatDateTime } = useDateTime();
    const { toast, showToast, dismissToast } = useToast();

    const groups = computed(() => page.props.groups ?? []);
    const filteredGroupsTotal = computed(() => groups.value.reduce((total, group) => total + group.total, 0));
    const translations = computed(
        () => page.props.translations ?? { data: [], current_page: 1, last_page: 1, per_page: 25, total: 0 }
    );
    const locales = computed(() => page.props.locales ?? []);
    const baseLocale = computed(() => page.props.baseLocale ?? '');
    const sortOptions = computed<SelectOption[]>(() => page.props.sortOptions ?? []);
    const lastSyncAt = computed(() => page.props.lastSyncAt ?? null);
    const totalTranslations = computed(() => page.props.totalTranslations ?? 0);
    const aiStatus = computed(() => page.props.ai ?? { available: false });
    const dynamicPatterns = computed(() => page.props.dynamicPatterns ?? []);
    const fallbackRules = computed(() => page.props.fallbackRules ?? []);
    const voxRoutes = useVoxRoutes();
    const targetLocales = computed(() => locales.value.filter((locale) => locale !== baseLocale.value));
    const orderedLocales = computed(() => [baseLocale.value, ...[...targetLocales.value].sort()]);

    const statusToggleOptions = computed<ToggleOption[]>(() => [
        { value: '', label: 'All' },
        { value: 'new', label: 'New' },
        { value: 'updated', label: 'Updated' },
        { value: 'missing', label: 'Missing' },
        { value: 'empty', label: 'Empty' },
        { value: 'orphan', label: 'Orphan' },
        { value: 'dynamic', label: 'Dynamic usage' },
        { value: 'retained', label: 'Retained by rule' },
        { value: 'pending-deletion', label: 'Pending deletion' },
        { value: 'published-overrides', label: 'Published overrides' },
        { value: 'drafts', label: 'Drafts' },
        { value: 'pending', label: 'Pending review' },
        { value: 'approved', label: 'Approved' },
    ]);

    const selectedIds = ref<number[]>([]);
    const { perPage, selectedGroup, search, status, sort, applyFilters, selectGroup, clearSearch, clearFilters } =
        useManageFilters(translations.value.per_page ?? 25, () => {
            selectedIds.value = [];
        });

    const filtersElement = ref<HTMLElement | null>(null);
    const { isCompactMode } = useCompactHeader(filtersElement);

    const editTranslation = ref<TranslationItem | null>(null);
    const isCreatingDynamic = ref(false);
    const isBulkUpdating = ref(false);
    const isBulkTranslating = ref(false);
    const cleanupRows = ref<TranslationItem[]>([]);
    const cleanupAction = ref<CleanupAction>('delete');
    const isCleaning = ref(false);

    const pagination = computed(() => ({
        current_page: translations.value.current_page ?? 1,
        last_page: translations.value.last_page ?? 1,
        per_page: translations.value.per_page ?? 25,
        total: translations.value.total ?? 0,
    }));
    const allVisibleSelected = computed(
        () =>
            translations.value.data.length > 0 &&
            translations.value.data.every((translation) => selectedIds.value.includes(translation.id))
    );

    // Keep the open edit panel pointed at the refreshed row after the listing reloads.
    watch(
        () => translations.value.data,
        (items) => {
            const updated = items.find((item) => item.id === editTranslation.value?.id);

            if (updated) {
                editTranslation.value = updated;
            }
        }
    );

    function handleBulkError(errors: Record<string, string>): void {
        showToast(firstError(errors), 'error');
    }

    function setSelected(translationId: number, selected: boolean): void {
        selectedIds.value = selected
            ? Array.from(new Set([...selectedIds.value, translationId]))
            : selectedIds.value.filter((id) => id !== translationId);
    }

    function selectAllVisible(selected: boolean): void {
        const visibleIds = translations.value.data.map((translation) => translation.id);

        if (selected) {
            selectedIds.value = Array.from(new Set([...selectedIds.value, ...visibleIds]));

            return;
        }

        selectedIds.value = selectedIds.value.filter((id) => !visibleIds.includes(id));
    }

    function reviewCleanup(action: CleanupAction, rows?: TranslationItem[]): void {
        cleanupAction.value = action;
        cleanupRows.value = rows ?? translations.value.data.filter((row) => selectedIds.value.includes(row.id));
    }

    function finishCleanup(): void {
        cleanupRows.value = [];
        selectedIds.value = [];
        editTranslation.value = null;
        showToast('Translation selection updated.');
    }

    function finishEdit(message: string): void {
        editTranslation.value = null;
        showToast(message);
    }

    function bulkApproval(targetStatus: 'pending' | 'approved'): void {
        if (selectedIds.value.length === 0) {
            return;
        }

        isBulkUpdating.value = true;

        router.post(
            voxRoutes.value?.manage_translation_bulk_approval ?? '',
            {
                ids: selectedIds.value,
                status: targetStatus,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onError: handleBulkError,
                onSuccess: (successPage) => {
                    showToast(flashSuccess(successPage, 'Translation statuses updated.'));
                    selectedIds.value = [];
                },
                onFinish: () => {
                    isBulkUpdating.value = false;
                },
            }
        );
    }

    function bulkTranslateMissing(): void {
        if (selectedIds.value.length === 0 || !aiStatus.value.available) {
            return;
        }

        isBulkTranslating.value = true;

        router.post(
            voxRoutes.value?.manage_translation_bulk_translate ?? '',
            { ids: selectedIds.value },
            {
                preserveScroll: true,
                preserveState: true,
                onError: handleBulkError,
                onSuccess: (successPage) => {
                    showToast(flashSuccess(successPage, 'Missing translations were translated.'));
                },
                onFinish: () => {
                    isBulkTranslating.value = false;
                },
            }
        );
    }

    function toggleApproval(translation: TranslationItem): void {
        router.post(
            translationActionUrl(voxRoutes.value?.manage_translation_toggle_approval, translation.id),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onError: handleBulkError,
                onSuccess: (successPage) => {
                    showToast(flashSuccess(successPage, 'Translation status updated.'));
                },
            }
        );
    }
</script>

<template>
    <Head title="Manage" />

    <div class="space-y-4">
        <!-- Page Header -->
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Manage Translations</h1>
                <p class="text-muted-foreground mt-1 text-sm">
                    Browse, edit, create dynamic values, and approve translations across all locales.
                </p>
            </div>
            <Button
                data-test="add-dynamic-translation"
                :disabled="dynamicPatterns.length === 0"
                class="shrink-0"
                variant="outline"
                @click="isCreatingDynamic = true"
            >
                <Plus class="size-4" />
                Add dynamic translation
            </Button>
        </div>

        <!-- Stats Cards -->
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="bg-card rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium tracking-wide uppercase">Groups</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ groups.length }}</p>
            </div>
            <div class="bg-card rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium tracking-wide uppercase">Translations</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ totalTranslations }}</p>
            </div>
            <div class="bg-card rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium tracking-wide uppercase">Last Sync</p>
                <p class="mt-1 text-sm font-medium">
                    {{ formatDateTime(lastSyncAt, 'Not synced yet') }}
                </p>
            </div>
        </div>

        <CompactFilterBar
            v-if="isCompactMode"
            v-model:search="search"
            v-model:sort="sort"
            v-model:status="status"
            :groups="groups"
            :selected-group="selectedGroup"
            :total="filteredGroupsTotal"
            :sort-options="sortOptions"
            :status-options="statusToggleOptions"
            @apply="applyFilters(1)"
            @clear="clearFilters"
            @clear-search="clearSearch"
            @select-group="selectGroup"
        />

        <!-- Main Content -->
        <div class="grid gap-4">
            <!-- Group filters -->
            <aside class="space-y-4">
                <GroupFilter
                    :groups="groups"
                    :selected-group="selectedGroup"
                    :total="filteredGroupsTotal"
                    @select="selectGroup"
                />

                <FallbackSettings
                    :base-locale="baseLocale"
                    :target-locales="targetLocales"
                    :selected-group="selectedGroup"
                    :fallback-rules="fallbackRules"
                />
            </aside>

            <!-- Main: Translations List -->
            <div class="min-w-0 space-y-4">
                <!-- Keep filters in flow so the compact header cannot change the scroll range. -->
                <section
                    ref="filtersElement"
                    class="bg-card rounded-xl border p-4"
                >
                    <div class="flex flex-col gap-4">
                        <!-- Search Row -->
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                            <SearchInput
                                v-model="search"
                                class="flex-1"
                                placeholder="Search by key, group, or value..."
                                @clear="clearSearch"
                            />
                            <div class="flex items-center gap-2">
                                <Select
                                    v-model="sort"
                                    :options="sortOptions"
                                    class="w-40 lg:w-56"
                                    @update:model-value="applyFilters(1)"
                                />
                                <Tooltip text="Reset all filters">
                                    <Button
                                        aria-label="Reset all filters"
                                        class="shrink-0"
                                        size="icon"
                                        variant="ghost"
                                        @click="clearFilters"
                                    >
                                        <RotateCcw class="size-4" />
                                    </Button>
                                </Tooltip>
                            </div>
                        </div>

                        <!-- Status Toggle -->
                        <div class="flex flex-wrap items-center gap-4">
                            <ToggleGroup
                                v-model="status"
                                class="max-w-full flex-wrap"
                                :options="statusToggleOptions"
                                size="sm"
                                @update:model-value="applyFilters(1)"
                            />
                        </div>
                    </div>
                </section>

                <!-- Translations List -->
                <section class="bg-card rounded-xl border">
                    <div
                        v-if="translations.data.length > 0"
                        class="bg-muted/20 flex flex-col gap-3 border-b px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="flex items-center gap-2">
                            <span @click.stop>
                                <Checkbox
                                    aria-label="Select all visible translations"
                                    :model-value="allVisibleSelected"
                                    @update:model-value="selectAllVisible"
                                />
                            </span>
                            <span class="text-muted-foreground text-xs">
                                {{
                                    selectedIds.length > 0
                                        ? `${selectedIds.length} selected`
                                        : 'Select all visible translations'
                                }}
                            </span>
                        </div>
                        <div
                            v-if="selectedIds.length > 0"
                            class="flex flex-wrap items-center gap-2"
                        >
                            <Button
                                variant="outline"
                                @click="reviewCleanup('delete')"
                                >Delete keys</Button
                            >
                            <Button
                                variant="outline"
                                @click="reviewCleanup('restore')"
                                >Cancel deletion</Button
                            >
                            <Button
                                data-test="bulk-translate-missing"
                                :disabled="isBulkUpdating || isBulkTranslating || !aiStatus.available"
                                size="sm"
                                variant="outline"
                                @click="bulkTranslateMissing"
                            >
                                <Sparkles class="size-4" />
                                {{ isBulkTranslating ? 'Translating…' : 'AI translate missing' }}
                            </Button>
                            <Button
                                :disabled="isBulkUpdating || isBulkTranslating"
                                size="sm"
                                variant="outline"
                                @click="bulkApproval('pending')"
                            >
                                Return to review
                            </Button>
                            <Button
                                :disabled="isBulkUpdating || isBulkTranslating"
                                size="sm"
                                @click="bulkApproval('approved')"
                            >
                                <Check class="size-4" />
                                Approve
                            </Button>
                        </div>
                    </div>

                    <!-- Empty State -->
                    <div
                        v-if="translations.data.length === 0"
                        class="flex flex-col items-center justify-center py-16 text-center"
                    >
                        <FileText class="text-muted-foreground/50 size-12" />
                        <p class="text-muted-foreground mt-4 text-sm">No translations match the current filters.</p>
                        <Button
                            class="mt-4"
                            size="sm"
                            variant="outline"
                            @click="clearFilters"
                        >
                            Clear filters
                        </Button>
                    </div>

                    <!-- Compact List -->
                    <div v-else>
                        <div
                            v-for="translation in translations.data"
                            :key="translation.id"
                            class="border-b last:border-b-0"
                        >
                            <TranslationRow
                                :translation="translation"
                                :base-locale="baseLocale"
                                :selected="selectedIds.includes(translation.id)"
                                :delete-disabled="isCleaning"
                                @open="editTranslation = translation"
                                @update:selected="setSelected(translation.id, $event)"
                                @delete="reviewCleanup('delete', [translation])"
                                @toggle-approval="toggleApproval(translation)"
                            />
                        </div>
                    </div>

                    <!-- Pagination -->
                    <div
                        v-if="translations.data.length > 0"
                        class="bg-muted/30 flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3"
                    >
                        <p class="text-muted-foreground text-xs">
                            Page {{ pagination.current_page }} of {{ pagination.last_page }}
                            <span class="hidden sm:inline">• {{ pagination.total }} items</span>
                        </p>
                        <PageSizeSelect
                            v-model="perPage"
                            @update:model-value="applyFilters(1)"
                        />
                        <div class="flex items-center gap-1">
                            <PaginationNav
                                :current-page="pagination.current_page"
                                :last-page="pagination.last_page"
                                button-class="size-8"
                                @change="applyFilters"
                            />
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <DynamicTranslationPanel
        :open="isCreatingDynamic"
        :patterns="dynamicPatterns"
        :locales="orderedLocales"
        :base-locale="baseLocale"
        :ai-available="aiStatus.available"
        :store-route="voxRoutes?.manage_translation_store"
        :translate-draft-route="voxRoutes?.manage_translation_translate_draft"
        @close="isCreatingDynamic = false"
        @created="showToast"
    />

    <TranslationEditPanel
        :translation="editTranslation"
        :locales="orderedLocales"
        :base-locale="baseLocale"
        :ai-available="aiStatus.available"
        :fallback-rules="fallbackRules"
        :missing-translation-prefix="page.props.missingTranslationPrefix ?? '🚩'"
        :update-route="voxRoutes?.manage_translation_update"
        :translate-route="voxRoutes?.manage_translation_translate"
        :use-application-route="voxRoutes?.manage_translation_use_application"
        @close="editTranslation = null"
        @saved="finishEdit"
        @notify="showToast"
        @cleanup="(action, translation) => reviewCleanup(action, [translation])"
    />

    <CleanupPanel
        v-model:busy="isCleaning"
        :rows="cleanupRows"
        :action="cleanupAction"
        :route="voxRoutes?.manage_translation_cleanup"
        @close="cleanupRows = []"
        @done="finishCleanup"
    />

    <ToastNotification
        :toast="toast"
        @dismiss="dismissToast"
    />
</template>
