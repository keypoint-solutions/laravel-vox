<script setup lang="ts">
    import { Head, router, usePage } from '@inertiajs/vue3';
    import { Check, CircleAlert, FileCheck2, UploadCloud } from '@lucide/vue';
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
            pending: number;
            incomplete: number;
        };
        lastPublishAt: string | null;
    }

    const page = usePage<PublishPageProps>();
    const { formatDateTime } = useDateTime();
    const stats = computed(() => page.props.stats ?? { approved: 0, pending: 0, incomplete: 0 });
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
        <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-muted-foreground text-xs font-medium tracking-[0.2em] uppercase">Language files</p>
                <h1 class="mt-2 text-2xl font-semibold">Publish approved translations</h1>
                <p class="text-muted-foreground mt-2 max-w-2xl text-sm">
                    Write reviewed database values back to Laravel PHP and JSON files. Pending and incomplete
                    translations remain untouched.
                </p>
            </div>
            <Button
                :disabled="isPublishing || stats.approved === 0"
                class="shrink-0"
                @click="publish"
            >
                <UploadCloud class="size-4" />
                {{ isPublishing ? 'Publishing…' : 'Publish approved' }}
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
            <div class="grid divide-y sm:grid-cols-3 sm:divide-x sm:divide-y-0">
                <div class="p-5">
                    <p class="text-muted-foreground text-xs font-medium tracking-wide uppercase">Approved</p>
                    <p class="mt-2 text-2xl font-semibold tabular-nums">{{ stats.approved }}</p>
                    <p class="text-muted-foreground mt-1 text-xs">Eligible after coverage checks</p>
                </div>
                <div class="p-5">
                    <p class="text-muted-foreground text-xs font-medium tracking-wide uppercase">Pending review</p>
                    <p class="mt-2 text-2xl font-semibold tabular-nums">{{ stats.pending }}</p>
                    <p class="text-muted-foreground mt-1 text-xs">Never written by Publish</p>
                </div>
                <div class="p-5">
                    <p class="text-muted-foreground text-xs font-medium tracking-wide uppercase">Incomplete approved</p>
                    <p class="mt-2 text-2xl font-semibold tabular-nums">{{ stats.incomplete }}</p>
                    <p class="text-muted-foreground mt-1 text-xs">Skipped until every locale is complete</p>
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
                {{ stats.incomplete }} approved
                {{ stats.incomplete === 1 ? 'translation is' : 'translations are' }} incomplete and will be skipped.
                Complete every configured locale in Manage before publishing.
            </p>
        </section>
    </div>
</template>
