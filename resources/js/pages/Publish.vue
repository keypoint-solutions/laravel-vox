<script setup lang="ts">
    import { Head, router, usePage } from '@inertiajs/vue3';
    import { Check, CircleAlert, FileCheck2, Sparkles, UploadCloud } from '@lucide/vue';
    import { computed, ref } from 'vue';

    import { Button } from '@/components/ui';
    import { useDateTime } from '@/composables/useDateTime';
    import Layout from '@/layouts/Layout.vue';

    defineOptions({
        layout: Layout,
    });

    interface PublishPageProps {
        stats: {
            approved: number;
            publishable: number;
            publishable_values: number;
            pending_deletions: number;
            pending: number;
            incomplete: number;
            dynamic: number;
            orphan: number;
        };
        lastPublishAt: string | null;
    }

    const page = usePage<PublishPageProps>();
    const { formatDateTime } = useDateTime();
    const stats = computed(
        () =>
            page.props.stats ?? {
                approved: 0,
                publishable: 0,
                publishable_values: 0,
                pending_deletions: 0,
                pending: 0,
                incomplete: 0,
                dynamic: 0,
                orphan: 0,
            }
    );
    const routes = computed(() => page.props.vox?.routes);
    const isPublishing = ref(false);
    const success = ref<string | null>(null);
    const error = ref<string | null>(null);

    function publish(): void {
        isPublishing.value = true;
        success.value = null;
        error.value = null;

        router.post(
            routes.value?.publish_store ?? '',
            {},
            {
                preserveScroll: true,
                onError: (errors) => {
                    error.value = Object.values(errors)[0] ?? 'Publishing failed.';
                },
                onSuccess: (responsePage) => {
                    success.value =
                        (responsePage.flash?.success as string | undefined) ?? 'Approved translations published.';
                },
                onFinish: () => {
                    isPublishing.value = false;
                },
            }
        );
    }
</script>

<template>
    <Head title="Publish" />

    <div class="space-y-6">
        <p
            v-if="stats.pending_deletions"
            role="alert"
            class="text-destructive rounded-lg border p-4"
        >
            {{ stats.pending_deletions }} keys are pending deletion. Publishing will remove them from language files and
            runtime catalogues.
        </p>
        <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-muted-foreground text-xs font-medium tracking-[0.2em] uppercase">Language files</p>
                <h1 class="mt-2 text-2xl font-semibold">Publish approved translations</h1>
                <p class="text-muted-foreground mt-2 max-w-2xl text-sm">
                    Publish approved changes and refresh previously published wording in language files and frontend
                    translations. You can publish even when there are no new changes. Unreviewed drafts stay
                    unpublished.
                </p>
            </div>
            <Button
                :disabled="isPublishing"
                class="shrink-0"
                @click="publish"
            >
                <UploadCloud class="size-4" />
                {{ isPublishing ? 'Publishing…' : 'Publish translations' }}
            </Button>
        </header>

        <div
            v-if="success"
            role="status"
            aria-live="polite"
            class="flex items-center gap-2 rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm text-emerald-600"
        >
            <Check class="size-4 shrink-0" />
            {{ success }}
        </div>

        <div
            v-if="error"
            role="alert"
            class="text-destructive border-destructive/40 bg-destructive/10 rounded-lg border p-3 text-sm"
        >
            {{ error }}
        </div>

        <section class="bg-card rounded-xl border">
            <div class="grid divide-y sm:grid-cols-5 sm:divide-x sm:divide-y-0">
                <div class="p-5">
                    <p class="text-muted-foreground text-xs font-medium tracking-wide uppercase">Publishable</p>
                    <p class="mt-2 text-2xl font-semibold tabular-nums">{{ stats.publishable }}</p>
                    <p class="text-muted-foreground mt-1 text-xs">
                        {{ stats.publishable_values }} approved locale values across these keys
                    </p>
                </div>
                <div class="p-5">
                    <p class="text-muted-foreground text-xs font-medium tracking-wide uppercase">Pending review</p>
                    <p class="mt-2 text-2xl font-semibold tabular-nums">{{ stats.pending }}</p>
                    <p class="text-muted-foreground mt-1 text-xs">Keys with wording still awaiting review</p>
                </div>
                <div class="p-5">
                    <p class="text-muted-foreground text-xs font-medium tracking-wide uppercase">Incomplete approved</p>
                    <p class="mt-2 text-2xl font-semibold tabular-nums">{{ stats.incomplete }}</p>
                    <p class="text-muted-foreground mt-1 text-xs">Keys with empty or flagged approved values</p>
                </div>
                <div class="p-5">
                    <p class="text-muted-foreground text-xs font-medium tracking-wide uppercase">Dynamic approved</p>
                    <p class="mt-2 text-2xl font-semibold tabular-nums">{{ stats.dynamic }}</p>
                    <p class="text-muted-foreground mt-1 text-xs">Approved locale values publish independently</p>
                </div>
                <div class="p-5">
                    <p class="text-muted-foreground text-xs font-medium tracking-wide uppercase">Orphan approved</p>
                    <p class="mt-2 text-2xl font-semibold tabular-nums">{{ stats.orphan }}</p>
                    <p class="text-muted-foreground mt-1 text-xs">No longer found locally</p>
                </div>
            </div>
        </section>

        <section class="bg-card grid gap-5 rounded-xl border p-5 sm:grid-cols-[1fr_auto] sm:items-center">
            <div class="flex items-start gap-3">
                <FileCheck2 class="mt-0.5 size-5 shrink-0 text-emerald-500" />
                <div>
                    <h2 class="text-sm font-semibold">Safe file update</h2>
                    <p class="text-muted-foreground mt-1 text-sm">
                        Existing keys, PHP comments, and obsolete-key comments are preserved. Deployment remains the
                        responsibility of the consuming application.
                    </p>
                </div>
            </div>
            <div class="text-left sm:text-right">
                <p class="text-muted-foreground text-xs uppercase">Last publish</p>
                <p class="mt-1 text-sm font-medium">
                    {{ formatDateTime(page.props.lastPublishAt, 'Not published yet') }}
                </p>
            </div>
        </section>

        <section
            v-if="stats.incomplete > 0"
            class="flex items-start gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4"
        >
            <CircleAlert class="mt-0.5 size-5 shrink-0 text-amber-500" />
            <p class="text-sm">
                {{ stats.incomplete }} {{ stats.incomplete === 1 ? 'key has' : 'keys have' }} empty or flagged approved
                values. Those values are skipped; other approved locales still publish.
            </p>
        </section>

        <section
            v-if="stats.dynamic > 0"
            class="flex items-start gap-3 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4"
        >
            <Sparkles class="mt-0.5 size-5 shrink-0 text-emerald-500" />
            <p class="text-sm">
                {{ stats.dynamic }} dynamic {{ stats.dynamic === 1 ? 'key has' : 'keys have' }} approved wording. Each
                nonempty, unflagged locale can publish independently.
            </p>
        </section>

        <section
            v-if="stats.orphan > 0"
            class="flex items-start gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4"
        >
            <CircleAlert class="mt-0.5 size-5 shrink-0 text-amber-500" />
            <p class="text-sm">
                {{ stats.orphan }} approved {{ stats.orphan === 1 ? 'translation is' : 'translations are' }} orphaned
                and will not be published. Review the Orphan filter in Manage.
            </p>
        </section>
    </div>
</template>
