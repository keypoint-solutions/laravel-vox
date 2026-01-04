<script setup lang="ts">
import Layout from '@/layouts/Layout.vue';
import { Head } from '@inertiajs/vue3';

defineOptions({
    layout: Layout,
});
</script>

<template>
    <Head title="Sync" />

    <div class="space-y-6">
        <section class="rounded-xl border bg-card p-6 shadow-sm">
            <p class="text-xs uppercase tracking-[0.2em] text-muted-foreground">
                Sync Center
            </p>
            <h1 class="mt-2 text-2xl font-semibold">Sync</h1>
            <p class="mt-2 text-sm text-muted-foreground">
                Configure remote environments, pull translations, and push updates
                through a controlled workflow.
            </p>
        </section>

        <section class="grid gap-4 sm:grid-cols-2">
            <div class="rounded-xl border bg-card p-5">
                <h2 class="text-lg font-semibold">Remote environments</h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Register staging and production endpoints to pull translations
                    with a secure sync key.
                </p>
            </div>
            <div class="rounded-xl border bg-card p-5">
                <h2 class="text-lg font-semibold">Sync activity</h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Monitor the last run, changes discovered, and items awaiting
                    review.
                </p>
            </div>
        </section>
    </div>
</template>
