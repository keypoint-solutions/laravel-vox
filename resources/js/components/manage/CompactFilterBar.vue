<script lang="ts" setup>
    import { ChevronDown, Laptop, RotateCcw } from '@lucide/vue';
    import { ref } from 'vue';

    import type { SelectOption, ToggleOption } from '@/components/ui';
    import { Button, SearchInput, Select, ToggleGroup, Tooltip } from '@/components/ui';
    import { frontendGroupTooltip } from '@/lib/manage';
    import { cn } from '@/lib/utils';
    import type { GroupItem } from '@/types/manage';

    defineProps<{
        groups: GroupItem[];
        selectedGroup: string | null;
        total: number;
        sortOptions: SelectOption[];
        statusOptions: ToggleOption[];
    }>();

    const emit = defineEmits<{
        apply: [];
        clear: [];
        clearSearch: [];
        selectGroup: [group: string | null];
    }>();

    const search = defineModel<string>('search', { required: true });
    const sort = defineModel<string>('sort', { required: true });
    const status = defineModel<string>('status', { required: true });

    const showGroupsDropdown = ref(false);
</script>

<template>
    <div
        class="bg-background/95 supports-backdrop-filter:bg-background/60 fixed inset-x-0 top-0 z-50 border-b backdrop-blur"
    >
        <div class="mx-auto max-w-7xl space-y-3 px-4 py-3 lg:px-8">
            <!-- Row 1: Groups + Search + Reset -->
            <div class="flex items-center gap-2 sm:gap-3">
                <!-- Groups Dropdown -->
                <div class="relative shrink-0">
                    <button
                        class="bg-card hover:bg-muted flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors"
                        type="button"
                        @click="showGroupsDropdown = !showGroupsDropdown"
                    >
                        <span class="max-w-25 truncate sm:max-w-30">{{ selectedGroup ?? 'All groups' }}</span>
                        <ChevronDown class="size-4 shrink-0 opacity-50" />
                    </button>
                    <div
                        v-if="showGroupsDropdown"
                        class="bg-card absolute top-full left-0 z-50 mt-1 max-h-64 w-56 overflow-y-auto rounded-lg border shadow-lg"
                    >
                        <button
                            :class="
                                cn(
                                    'flex w-full items-center justify-between px-3 py-2 text-sm transition-colors',
                                    selectedGroup === null ? 'bg-primary/10 text-primary font-medium' : 'hover:bg-muted'
                                )
                            "
                            type="button"
                            @click="
                                emit('selectGroup', null);
                                showGroupsDropdown = false;
                            "
                        >
                            <span>All groups</span>
                            <span class="text-muted-foreground text-xs tabular-nums">{{ total }}</span>
                        </button>
                        <button
                            v-for="group in groups"
                            :key="group.name"
                            :class="
                                cn(
                                    'flex w-full items-center justify-between px-3 py-2 text-sm transition-colors',
                                    selectedGroup === group.name
                                        ? 'bg-primary/10 text-primary font-medium'
                                        : 'hover:bg-muted'
                                )
                            "
                            type="button"
                            @click="
                                emit('selectGroup', group.name);
                                showGroupsDropdown = false;
                            "
                        >
                            <span class="flex min-w-0 items-center gap-1.5">
                                <Tooltip
                                    v-if="group.is_frontend_exported"
                                    :text="frontendGroupTooltip(group)"
                                >
                                    <Laptop class="size-3 shrink-0 text-emerald-500" />
                                </Tooltip>
                                <span class="truncate">{{ group.name }}</span>
                            </span>
                            <span class="text-muted-foreground text-xs tabular-nums">{{ group.total }}</span>
                        </button>
                    </div>
                </div>

                <!-- Search -->
                <SearchInput
                    v-model="search"
                    class="min-w-0 flex-1"
                    placeholder="Search..."
                    @clear="emit('clearSearch')"
                />

                <div class="hidden shrink-0 items-center gap-2 sm:flex">
                    <label
                        class="sr-only"
                        for="sticky-sort-desktop"
                        >Sort translations</label
                    >
                    <Select
                        id="sticky-sort-desktop"
                        v-model="sort"
                        :options="sortOptions"
                        class="w-44 lg:w-56"
                        @update:model-value="emit('apply')"
                    />
                    <Tooltip text="Reset all filters">
                        <Button
                            aria-label="Reset all filters"
                            size="icon"
                            variant="ghost"
                            @click="emit('clear')"
                        >
                            <RotateCcw class="size-4" />
                        </Button>
                    </Tooltip>
                </div>
            </div>

            <div class="hidden overflow-x-auto pb-1 sm:block">
                <ToggleGroup
                    v-model="status"
                    :options="statusOptions"
                    class="whitespace-nowrap"
                    size="sm"
                    aria-label="Filter translations by status"
                    @update:model-value="emit('apply')"
                />
            </div>

            <div class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] items-center gap-2 sm:hidden">
                <label
                    class="sr-only"
                    for="sticky-status"
                    >Filter translations by status</label
                >
                <Select
                    id="sticky-status"
                    v-model="status"
                    :options="statusOptions"
                    @update:model-value="emit('apply')"
                />
                <label
                    class="sr-only"
                    for="sticky-sort-mobile"
                    >Sort translations</label
                >
                <Select
                    id="sticky-sort-mobile"
                    v-model="sort"
                    :options="
                        sortOptions.map((option) => ({
                            ...option,
                            label: option.value === 'updated_desc' ? 'Newest' : option.label,
                        }))
                    "
                    @update:model-value="emit('apply')"
                />
                <Tooltip text="Reset all filters">
                    <Button
                        aria-label="Reset all filters"
                        size="icon"
                        variant="ghost"
                        @click="emit('clear')"
                    >
                        <RotateCcw class="size-4" />
                    </Button>
                </Tooltip>
            </div>
        </div>
    </div>

    <!-- Click outside to close groups dropdown -->
    <div
        v-if="showGroupsDropdown"
        class="fixed inset-0 z-40"
        @click="showGroupsDropdown = false"
    />
</template>
