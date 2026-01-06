<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';

    import Layout from '@/layouts/Layout.vue';

    defineOptions({
        layout: Layout,
    });
</script>

<template>
    <Head title="Publish" />

    <div class="space-y-6">
        <section class="bg-card rounded-xl border p-6 shadow-sm">
            <p class="text-muted-foreground text-xs tracking-[0.2em] uppercase">Release Flow</p>
            <h1 class="mt-2 text-2xl font-semibold">Publish</h1>
            <p class="text-muted-foreground mt-2 text-sm">
                Bundle translations, trigger builds, and promote approved keys to production-ready assets.
            </p>
        </section>

        <section class="grid gap-4 sm:grid-cols-2">
            <div class="bg-card rounded-xl border p-5">
                <h2 class="text-lg font-semibold">Build status</h2>
                <p class="text-muted-foreground mt-2 text-sm">
                    Track the latest translation build and deployment pipeline.
                </p>
            </div>
            <div class="bg-card rounded-xl border p-5">
                <h2 class="text-lg font-semibold">Release checklist</h2>
                <p class="text-muted-foreground mt-2 text-sm">
                    Confirm locale coverage, missing keys, and approval thresholds before publish.
                </p>
            </div>
        </section>
    </div>
</template>
