<script setup lang="ts">
import Layout from '@/layouts/Layout.vue';
import { Head } from '@inertiajs/vue3';

defineOptions({
    layout: Layout,
});
</script>

<template>
    <Head title="Settings" />

    <div class="space-y-6">
        <section class="rounded-xl border bg-card p-6 shadow-sm">
            <p class="text-xs uppercase tracking-[0.2em] text-muted-foreground">
                Configuration
            </p>
            <h1 class="mt-2 text-2xl font-semibold">Settings</h1>
            <p class="mt-2 text-sm text-muted-foreground">
                Control parser rules, translation drivers, and sync keys from a
                single view.
            </p>
        </section>

        <section class="grid gap-4 sm:grid-cols-2">
            <div class="rounded-xl border bg-card p-5">
                <h2 class="text-lg font-semibold">Parsing rules</h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Manage included paths, exclusions, and obsolete key
                    handling.
                </p>
            </div>
            <div class="rounded-xl border bg-card p-5">
                <h2 class="text-lg font-semibold">Translation driver</h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Set API keys, prompts, and protected terms for AI
                    translations.
                </p>
            </div>
        </section>
    </div>
</template>
