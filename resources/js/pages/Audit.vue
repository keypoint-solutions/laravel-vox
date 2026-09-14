<script setup lang="ts">
    import { Head, router, usePage } from '@inertiajs/vue3';
    import { ChevronLeft, ChevronRight, ClipboardList, UserRound } from '@lucide/vue';
    import { computed, ref } from 'vue';

    import { Badge, Button, Tooltip } from '@/components/ui';
    import PageSizeSelect from '@/components/ui/PageSizeSelect.vue';
    import { useDateTime } from '@/composables/useDateTime';
    import Layout from '@/layouts/Layout.vue';

    defineOptions({
        layout: Layout,
    });

    interface AuditItem {
        id: number;
        action: string;
        context: Record<string, unknown>;
        user_id: number | null;
        created_at: string | null;
    }

    interface AuditPayload {
        data: AuditItem[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    }

    const page = usePage<{ audits: AuditPayload }>();
    const { formatDateTime } = useDateTime();
    const audits = computed(
        () => page.props.audits ?? { data: [], current_page: 1, last_page: 1, per_page: 25, total: 0 }
    );

    const perPage = ref(audits.value.per_page ?? 25);

    const actionLabels: Record<string, string> = {
        'data-reset': 'Reset Vox data',
        parse: 'Parsed source translations',
        publish: 'Published translations',
        sync: 'Synchronized local files',
        'sync-remote': 'Pulled remote translations',
        'remote-reconciliation': 'Reviewed remote changes',
        'translation-approved': 'Approved a translation',
        'translation-reopened': 'Returned a translation to review',
        'translations-bulk-approved': 'Bulk approved translations',
        'translations-bulk-reopened': 'Bulk returned translations to review',
        'translation-updated': 'Updated translation values',
        translate: 'Translated values with AI',
    };

    function actionLabel(action: string): string {
        return actionLabels[action] ?? action.replaceAll('-', ' ');
    }

    function contextEntries(context: Record<string, unknown>): Array<[string, string]> {
        return Object.entries(context)
            .filter(([, value]) => value !== null && value !== '' && value !== undefined)
            .map(([key, value]) => {
                const label = key.replaceAll('_', ' ');
                const formatted = Array.isArray(value)
                    ? value.join(', ')
                    : typeof value === 'object'
                      ? JSON.stringify(value)
                      : String(value);

                return [label, formatted];
            });
    }

    function goToPage(pageNumber: number): void {
        router.get(
            page.url.split('?')[0],
            {
                ...Object.fromEntries(new URLSearchParams(page.url.split('?')[1])),
                page: pageNumber,
                per_page: perPage.value,
            },
            {
                preserveScroll: true,
                preserveState: true,
            }
        );
    }
</script>

<template>
    <Head title="Audit" />

    <div class="space-y-6">
        <header>
            <p class="text-muted-foreground text-xs font-medium tracking-[0.2em] uppercase">Activity history</p>
            <h1 class="mt-2 text-2xl font-semibold">Audit trail</h1>
            <p class="text-muted-foreground mt-2 max-w-2xl text-sm">
                Review synchronization, publishing, moderation, parsing, and translation actions in local time.
            </p>
        </header>

        <section class="bg-card rounded-xl border">
            <div
                v-if="audits.data.length === 0"
                class="p-10 text-center"
            >
                <ClipboardList class="text-muted-foreground/50 mx-auto size-10" />
                <p class="mt-3 text-sm font-medium">No activity recorded yet</p>
                <p class="text-muted-foreground mt-1 text-xs">Sync, Publish, and moderation actions appear here.</p>
            </div>

            <ol v-else>
                <li
                    v-for="audit in audits.data"
                    :key="audit.id"
                    class="grid gap-3 border-b p-4 last:border-b-0 sm:grid-cols-[minmax(0,1fr)_auto]"
                >
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-sm font-semibold">{{ actionLabel(audit.action) }}</p>
                            <Badge variant="outline">{{ audit.action }}</Badge>
                        </div>
                        <dl
                            v-if="contextEntries(audit.context).length > 0"
                            class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs"
                        >
                            <div
                                v-for="[label, value] in contextEntries(audit.context)"
                                :key="label"
                                class="flex min-w-0 gap-1"
                            >
                                <dt class="text-muted-foreground capitalize">{{ label }}:</dt>
                                <dd class="max-w-md truncate font-medium">{{ value }}</dd>
                            </div>
                        </dl>
                    </div>
                    <div class="text-left sm:text-right">
                        <p class="text-muted-foreground text-xs">
                            {{ formatDateTime(audit.created_at) }}
                        </p>
                        <p
                            v-if="audit.user_id"
                            class="text-muted-foreground mt-1 inline-flex items-center gap-1 text-xs"
                        >
                            <UserRound class="size-3" />
                            User {{ audit.user_id }}
                        </p>
                    </div>
                </li>
            </ol>

            <div class="bg-muted/20 flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3">
                <p class="text-muted-foreground text-xs">
                    Page {{ audits.current_page }} of {{ audits.last_page }} · {{ audits.total }} events
                </p>
                <PageSizeSelect
                    v-model="perPage"
                    @update:model-value="goToPage(1)"
                />
                <div class="flex items-center gap-1">
                    <Tooltip text="Previous page">
                        <Button
                            aria-label="Previous page"
                            :disabled="audits.current_page <= 1"
                            size="icon"
                            variant="ghost"
                            @click="goToPage(audits.current_page - 1)"
                        >
                            <ChevronLeft class="size-4" />
                        </Button>
                    </Tooltip>
                    <Tooltip text="Next page">
                        <Button
                            aria-label="Next page"
                            :disabled="audits.current_page >= audits.last_page"
                            size="icon"
                            variant="ghost"
                            @click="goToPage(audits.current_page + 1)"
                        >
                            <ChevronRight class="size-4" />
                        </Button>
                    </Tooltip>
                </div>
            </div>
        </section>
    </div>
</template>
