<script lang="ts" setup>
    import { Laptop } from '@lucide/vue';
    import { computed, ref } from 'vue';

    import { Badge, SearchInput, Tooltip } from '@/components/ui';
    import { frontendGroupTooltip } from '@/lib/manage';
    import { cn } from '@/lib/utils';
    import type { GroupItem } from '@/types/manage';

    const props = defineProps<{
        groups: GroupItem[];
        selectedGroup: string | null;
        total: number;
    }>();

    const emit = defineEmits<{
        select: [group: string | null];
    }>();

    const search = ref('');

    const filteredGroups = computed(() => {
        const term = search.value.trim().toLowerCase();
        if (term === '') {
            return props.groups;
        }

        return props.groups.filter((group) => group.name.toLowerCase().includes(term));
    });
</script>

<template>
    <section class="bg-card rounded-xl border p-4">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold">Groups</h2>
            <span class="text-muted-foreground text-xs">{{ groups.length }}</span>
        </div>
        <SearchInput
            v-model="search"
            class="mt-3"
            placeholder="Filter groups..."
            @clear="search = ''"
        />
        <div class="mt-3 flex max-h-64 flex-wrap gap-2 overflow-y-auto">
            <button
                :class="
                    cn(
                        'inline-flex min-h-9 max-w-full items-center gap-2 rounded-full border px-3 py-1.5 text-xs transition-colors',
                        selectedGroup === null
                            ? 'border-primary/30 bg-primary/10 text-primary font-medium'
                            : 'border-border text-muted-foreground hover:bg-muted hover:text-foreground'
                    )
                "
                type="button"
                :aria-pressed="selectedGroup === null"
                @click="emit('select', null)"
            >
                <span>All groups</span>
                <span class="shrink-0 text-xs tabular-nums opacity-70">{{ total }}</span>
            </button>
            <button
                v-for="group in filteredGroups"
                :key="group.name"
                :class="
                    cn(
                        'inline-flex min-h-9 max-w-full items-center gap-2 rounded-full border px-3 py-1.5 text-xs transition-colors',
                        selectedGroup === group.name
                            ? 'border-primary/30 bg-primary/10 text-primary font-medium'
                            : 'border-border text-muted-foreground hover:bg-muted hover:text-foreground'
                    )
                "
                type="button"
                :aria-pressed="selectedGroup === group.name"
                :title="group.name"
                @click="emit('select', group.name)"
            >
                <span class="flex min-w-0 items-center gap-1.5">
                    <Tooltip
                        v-if="group.is_frontend_exported"
                        data-test="group-frontend-tooltip"
                        :text="frontendGroupTooltip(group)"
                    >
                        <Laptop class="size-3.5 shrink-0 text-emerald-500" />
                    </Tooltip>
                    <span class="truncate">{{ group.name }}</span>
                    <Badge
                        v-if="group.is_json"
                        class="shrink-0"
                        variant="secondary"
                    >
                        JSON
                    </Badge>
                </span>
                <span class="shrink-0 text-xs tabular-nums opacity-70">{{ group.total }}</span>
            </button>
        </div>
    </section>
</template>
