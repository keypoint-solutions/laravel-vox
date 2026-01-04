<script setup lang="ts">
import Layout from '@/layouts/Layout.vue';
import { Head } from '@inertiajs/vue3';

defineOptions({
    layout: Layout,
});
</script>

<template>
    <Head title="Manage" />

    <div class="space-y-6">
        <section class="rounded-xl border bg-card p-6 shadow-sm">
            <p class="text-xs uppercase tracking-[0.2em] text-muted-foreground">
                Translation Desk
            </p>
            <h1 class="mt-2 text-2xl font-semibold">Manage</h1>
            <p class="mt-2 text-sm text-muted-foreground">
                Review keys, context snippets, and moderation status for every
                locale in one workspace.
            </p>
        </section>

        <section class="grid gap-4 sm:grid-cols-2">
            <div class="rounded-xl border bg-card p-5">
                <h2 class="text-lg font-semibold">Pending reviews</h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Track new or updated translations that need approval.
                </p>
            </div>
            <div class="rounded-xl border bg-card p-5">
                <h2 class="text-lg font-semibold">Key details</h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Inspect the first five occurrences with code context and
                    source references.
                </p>
            </div>
        </section>
    </div>
</template>
