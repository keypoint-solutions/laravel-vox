<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';

    import Layout from '@/layouts/Layout.vue';

    defineOptions({
        layout: Layout,
    });
</script>

<template>
    <Head title="Manage" />

    <div class="space-y-6">
        <section class="bg-card rounded-xl border p-6 shadow-sm">
            <p class="text-muted-foreground text-xs tracking-[0.2em] uppercase">Translation Desk</p>
            <h1 class="mt-2 text-2xl font-semibold">Manage</h1>
            <p class="text-muted-foreground mt-2 text-sm">
                Review keys, context snippets, and moderation status for every locale in one workspace.
            </p>
        </section>

        <section class="grid gap-4 sm:grid-cols-2">
            <div class="bg-card rounded-xl border p-5">
                <h2 class="text-lg font-semibold">Pending reviews</h2>
                <p class="text-muted-foreground mt-2 text-sm">Track new or updated translations that need approval.</p>
            </div>
            <div class="bg-card rounded-xl border p-5">
                <h2 class="text-lg font-semibold">Key details</h2>
                <p class="text-muted-foreground mt-2 text-sm">
                    Inspect the first five occurrences with code context and source references.
                </p>
            </div>
        </section>
    </div>
</template>
