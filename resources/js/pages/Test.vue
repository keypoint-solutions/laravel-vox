<script setup lang="ts">
import Layout from '@/layouts/Layout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineOptions({
    layout: Layout,
});
</script>

<template>
    <Head title="Vox Test" />

    <div class="space-y-6">
        <section class="rounded-xl border bg-card p-6 shadow-sm">
            <p class="text-xs uppercase tracking-[0.2em] text-muted-foreground">
                Sandbox
            </p>
            <h1 class="mt-2 text-2xl font-semibold">Test Page</h1>
            <p class="mt-2 text-sm text-muted-foreground">
                This page uses the shared layout to confirm persistent UI
                between routes.
            </p>
        </section>

        <Link
            href="/vox"
            class="inline-flex items-center gap-2 text-sm font-medium text-primary hover:underline"
        >
            Back to Dashboard
        </Link>
    </div>
</template>
