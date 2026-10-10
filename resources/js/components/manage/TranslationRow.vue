<script lang="ts" setup>
    import { Braces, Check, CircleAlert, Code, Laptop, Server, Trash2 } from '@lucide/vue';

    import { Badge, Button, Checkbox, Tooltip } from '@/components/ui';
    import { approvalActionLabel, canDelete, overrideLocales, workflowStatusLabel } from '@/lib/manage';
    import type { TranslationItem } from '@/types/manage';

    defineProps<{
        translation: TranslationItem;
        baseLocale: string;
        selected: boolean;
        deleteDisabled: boolean;
    }>();

    const emit = defineEmits<{
        open: [];
        delete: [];
        toggleApproval: [];
        'update:selected': [selected: boolean];
    }>();
</script>

<template>
    <div
        class="hover:bg-muted/30 flex cursor-pointer items-center gap-3 px-4 py-3 transition-colors"
        :data-test="`translation-row-${translation.id}`"
        @click="emit('open')"
    >
        <span @click.stop>
            <Checkbox
                :aria-label="`Select ${translation.display_key}`"
                :model-value="selected"
                @update:model-value="emit('update:selected', $event)"
            />
        </span>

        <!-- Status Indicator -->
        <Tooltip :text="workflowStatusLabel(translation)">
            <span
                :aria-label="workflowStatusLabel(translation)"
                data-test="workflow-status"
                :class="[
                    'size-2 shrink-0 rounded-full',
                    translation.status === 'approved' ? 'bg-emerald-500' : 'bg-amber-500',
                ]"
            />
        </Tooltip>

        <!-- Key & Group -->
        <div class="min-w-0 flex-1 overflow-hidden">
            <div class="flex min-w-0 items-center gap-2">
                <span class="min-w-0 truncate text-sm font-medium">{{ translation.display_key }}</span>
                <Badge
                    class="shrink-0"
                    variant="secondary"
                >
                    {{ translation.group ?? 'default' }}
                </Badge>
                <Badge
                    v-if="translation.freshness_status"
                    class="shrink-0 capitalize"
                    variant="outline"
                >
                    {{ translation.freshness_status }}
                </Badge>
                <Badge
                    v-if="translation.is_orphan"
                    class="shrink-0"
                    variant="warning"
                >
                    Orphan
                </Badge>
            </div>
            <div class="mt-1 flex flex-wrap gap-1.5">
                <Badge
                    v-if="overrideLocales(translation).length"
                    variant="secondary"
                    :title="`Published overrides: ${overrideLocales(translation).join(', ').toUpperCase()}`"
                >
                    Published override ·
                    {{ overrideLocales(translation).join(', ').toUpperCase() }}
                </Badge>
                <Badge
                    v-if="translation.draft_locales?.length"
                    variant="warning"
                >
                    Draft · {{ translation.draft_locales.join(', ').toUpperCase() }}
                </Badge>
                <Badge
                    v-if="translation.pending_publish_locales?.length"
                    variant="outline"
                >
                    Unpublished ·
                    {{ translation.pending_publish_locales.join(', ').toUpperCase() }}
                </Badge>
            </div>
            <p class="text-muted-foreground mt-0.5 line-clamp-1 text-xs">
                {{ translation.values?.[baseLocale] || '—' }}
            </p>
        </div>

        <!-- Meta -->
        <div class="text-muted-foreground hidden shrink-0 items-center gap-3 text-xs sm:flex">
            <Tooltip :text="translation.is_frontend ? 'Used in frontend code' : 'Used in backend code'">
                <span
                    :aria-label="translation.is_frontend ? 'Used in frontend code' : 'Used in backend code'"
                    class="flex items-center"
                >
                    <Laptop
                        v-if="translation.is_frontend"
                        class="size-3"
                    />
                    <Server
                        v-else
                        class="size-3"
                    />
                </span>
            </Tooltip>
            <Badge
                v-if="translation.is_retained"
                variant="secondary"
                >Retained</Badge
            >
            <Badge
                v-if="translation.is_pending_delete"
                variant="warning"
                >Pending deletion</Badge
            >
            <Tooltip
                v-if="translation.is_dynamic"
                :text="`Possible dynamic usage: ${translation.dynamic_pattern}`"
            >
                <Braces
                    aria-label="Dynamic translation key"
                    class="size-3 text-violet-500"
                />
            </Tooltip>
            <Tooltip
                v-if="translation.occurrences.length"
                :text="`${translation.occurrences.length} code occurrence${translation.occurrences.length === 1 ? '' : 's'}`"
            >
                <span class="flex items-center gap-1">
                    <Code class="size-3" />
                    {{ translation.occurrences.length }}
                </span>
            </Tooltip>
            <Tooltip
                v-if="translation.has_missing_values"
                text="One or more locale values are missing"
            >
                <CircleAlert
                    aria-label="One or more locale values are missing"
                    class="size-3 text-red-500"
                />
            </Tooltip>
        </div>

        <!-- Actions -->
        <div class="flex shrink-0 items-center gap-1">
            <Tooltip
                v-if="canDelete(translation)"
                text="Delete key"
            >
                <Button
                    aria-label="Delete key"
                    data-test="delete-translation"
                    class="text-destructive size-8"
                    size="icon"
                    variant="ghost"
                    :disabled="deleteDisabled || !canDelete(translation)"
                    @click.stop="emit('delete')"
                >
                    <Trash2 class="size-4" />
                </Button>
            </Tooltip>
            <Tooltip :text="approvalActionLabel(translation)">
                <Button
                    :aria-label="approvalActionLabel(translation)"
                    data-test="approval-action"
                    :class="translation.status === 'approved' ? 'text-emerald-500' : ''"
                    class="size-8"
                    size="icon"
                    variant="ghost"
                    @click.stop="emit('toggleApproval')"
                >
                    <Check class="size-4" />
                </Button>
            </Tooltip>
        </div>
    </div>
</template>
