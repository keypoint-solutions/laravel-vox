<script lang="ts" setup>
import Layout from '@/layouts/Layout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineOptions({
    layout: Layout,
});

const modules = [
    {
        title: 'Sync',
        description: 'Pull, compare, and merge translation files across environments.',
        href: '/vox/sync',
    },
    {
        title: 'Manage',
        description: 'Review keys, contexts, and moderation status in one place.',
        href: '/vox/manage',
    },
    {
        title: 'Publish',
        description: 'Ship verified translations to production-ready bundles.',
        href: '/vox/publish',
    },
    {
        title: 'Audit',
        description: 'Trace command runs, approvals, and translation activity.',
        href: '/vox/audit',
    },
    {
        title: 'Settings',
        description: 'Tune parsing, syncing, and translation driver preferences.',
        href: '/vox/settings',
    },
];
</script>

<template>
    <div class="grid gap-6">
        <Head title="Vox Dashboard" />

        <section class="rounded-xl border bg-card p-6 shadow-sm">
            <p class="text-xs uppercase tracking-[0.2em] text-muted-foreground">
                Overview
            </p>
            <h1 class="mt-2 text-2xl font-semibold">Dashboard</h1>
            <p class="mt-2 text-sm text-muted-foreground">
                Central place for translation management.
            </p>
        </section>

        <section class="grid gap-4 sm:grid-cols-2">
            <Link
                v-for="module in modules"
                :key="module.title"
                :href="module.href"
                class="group rounded-xl border bg-card p-5 transition hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-lg"
            >
                <h2 class="text-lg font-semibold">{{ module.title }}</h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    {{ module.description }}
                </p>
                <div
                    class="mt-4 text-sm font-medium text-primary transition group-hover:translate-x-1"
                >
                    Open module →
                </div>
            </Link>
        </section>
    </div>
</template>
