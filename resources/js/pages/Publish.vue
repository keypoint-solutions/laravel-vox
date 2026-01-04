<script setup lang="ts">
import Layout from '@/layouts/Layout.vue';
import { Head } from '@inertiajs/vue3';

defineOptions({
    layout: Layout,
});
</script>

<template>
    <Head title="Publish" />

    <div class="space-y-6">
        <section class="rounded-xl border bg-card p-6 shadow-sm">
            <p class="text-xs uppercase tracking-[0.2em] text-muted-foreground">
                Release Flow
            </p>
            <h1 class="mt-2 text-2xl font-semibold">Publish</h1>
            <p class="mt-2 text-sm text-muted-foreground">
                Bundle translations, trigger builds, and promote approved keys
                to production-ready assets.
            </p>
        </section>

        <section class="grid gap-4 sm:grid-cols-2">
            <div class="rounded-xl border bg-card p-5">
                <h2 class="text-lg font-semibold">Build status</h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Track the latest translation build and deployment pipeline.
                </p>
            </div>
            <div class="rounded-xl border bg-card p-5">
                <h2 class="text-lg font-semibold">Release checklist</h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Confirm locale coverage, missing keys, and approval
                    thresholds before publish.
                </p>
            </div>
        </section>
    </div>
</template>
