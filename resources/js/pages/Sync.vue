<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';

    import Layout from '@/layouts/Layout.vue';

    defineOptions({
        layout: Layout,
    });
</script>

<template>
    <Head title="Sync" />

    <div class="space-y-6">
        <section class="bg-card rounded-xl border p-6 shadow-sm">
            <p class="text-muted-foreground text-xs tracking-[0.2em] uppercase">Sync Center</p>
            <h1 class="mt-2 text-2xl font-semibold">Sync</h1>
            <p class="text-muted-foreground mt-2 text-sm">
                Configure remote environments, pull translations, and push updates through a controlled workflow.
            </p>
        </section>

        <section class="grid gap-4 sm:grid-cols-2">
            <div class="bg-card rounded-xl border p-5">
                <h2 class="text-lg font-semibold">Remote environments</h2>
                <p class="text-muted-foreground mt-2 text-sm">
                    Register staging and production endpoints to pull translations with a secure sync key.
                </p>
            </div>
            <div class="bg-card rounded-xl border p-5">
                <h2 class="text-lg font-semibold">Sync activity</h2>
                <p class="text-muted-foreground mt-2 text-sm">
                    Monitor the last run, changes discovered, and items awaiting review.
                </p>
            </div>
        </section>
    </div>
</template>
