<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';

    import Layout from '@/layouts/Layout.vue';

    defineOptions({
        layout: Layout,
    });
</script>

<template>
    <Head title="Audit" />

    <div class="space-y-6">
        <section class="bg-card rounded-xl border p-6 shadow-sm">
            <p class="text-muted-foreground text-xs tracking-[0.2em] uppercase">Audit Trail</p>
            <h1 class="mt-2 text-2xl font-semibold">Audit</h1>
            <p class="text-muted-foreground mt-2 text-sm">
                Review command runs, translation activity, and moderation actions with full context.
            </p>
        </section>

        <section class="bg-card rounded-xl border p-5">
            <h2 class="text-lg font-semibold">Recent activity</h2>
            <p class="text-muted-foreground mt-2 text-sm">
                Track who ran which command and when, including parameters and outcomes.
            </p>
        </section>
    </div>
</template>
