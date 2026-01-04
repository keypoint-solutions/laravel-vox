<script setup lang="ts">
import Layout from '@/layouts/Layout.vue';
import { Head } from '@inertiajs/vue3';

defineOptions({
    layout: Layout,
});
</script>

<template>
    <Head title="Audit" />

    <div class="space-y-6">
        <section class="rounded-xl border bg-card p-6 shadow-sm">
            <p class="text-xs uppercase tracking-[0.2em] text-muted-foreground">
                Audit Trail
            </p>
            <h1 class="mt-2 text-2xl font-semibold">Audit</h1>
            <p class="mt-2 text-sm text-muted-foreground">
                Review command runs, translation activity, and moderation
                actions with full context.
            </p>
        </section>

        <section class="rounded-xl border bg-card p-5">
            <h2 class="text-lg font-semibold">Recent activity</h2>
            <p class="mt-2 text-sm text-muted-foreground">
                Track who ran which command and when, including parameters and
                outcomes.
            </p>
        </section>
    </div>
</template>
