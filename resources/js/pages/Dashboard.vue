<script lang="ts" setup>
    import { Head, Link, usePage } from '@inertiajs/vue3';
    import { FileText, Globe, Languages, Layers, RefreshCw, Settings, UploadCloud } from '@lucide/vue';
    import { computed } from 'vue';

    import Layout from '@/layouts/Layout.vue';

    defineOptions({
        layout: Layout,
    });

    interface StatsProps {
        totalKeys: number;
        locales: number;
        pendingReview: number;
        lastSync: string;
    }

    const page = usePage<{ stats: StatsProps }>();
    const stats = computed(() => page.props.stats ?? {});
    const features = computed(() => page.props.vox?.features ?? {});

    const modules = computed(() =>
        [
            {
                title: 'Sync',
                description: 'Pull, compare, and merge translation files across environments.',
                href: '/vox/sync',
                icon: RefreshCw,
                color: 'bg-blue-500/10 text-blue-500',
                feature: 'sync',
            },
            {
                title: 'Manage',
                description: 'Review keys, contexts, and moderation status in one place.',
                href: '/vox/manage',
                icon: Layers,
                color: 'bg-emerald-500/10 text-emerald-500',
                feature: 'manage',
            },
            {
                title: 'Publish',
                description: 'Ship verified translations to production-ready bundles.',
                href: '/vox/publish',
                icon: UploadCloud,
                color: 'bg-violet-500/10 text-violet-500',
                feature: 'publish',
            },
            {
                title: 'Audit',
                description: 'Trace command runs, approvals, and translation activity.',
                href: '/vox/audit',
                icon: FileText,
                color: 'bg-amber-500/10 text-amber-500',
                feature: 'audit',
            },
            {
                title: 'Settings',
                description: 'Tune parsing, syncing, and translation driver preferences.',
                href: '/vox/settings',
                icon: Settings,
                color: 'bg-slate-500/10 text-slate-500',
                feature: 'settings',
            },
        ].filter((item) => features.value[item.feature] !== false)
    );

    const overviewStats = computed(() => [
        {
            label: 'Total Keys',
            value: stats.value.totalKeys ?? '—',
            icon: Languages,
            color: 'text-violet-500',
        },
        {
            label: 'Locales',
            value: stats.value.locales ?? '—',
            icon: Globe,
            color: 'text-blue-500',
        },
        {
            label: 'Pending Review',
            value: stats.value.pendingReview ?? '—',
            icon: FileText,
            color: 'text-amber-500',
        },
        {
            label: 'Last Sync',
            value: stats.value.lastSync ?? '—',
            icon: RefreshCw,
            color: 'text-emerald-500',
        },
    ]);
</script>

<template>
    <div class="space-y-8">
        <Head title="Vox Dashboard" />

        <!-- Page Header -->
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Dashboard</h1>
            <p class="text-muted-foreground mt-1 text-sm">Central place for translation management.</p>
        </div>

        <!-- Stats Overview -->
        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div
                v-for="stat in overviewStats"
                :key="stat.label"
                class="bg-card rounded-xl border p-5"
            >
                <div class="flex items-center justify-between">
                    <span class="text-muted-foreground text-sm font-medium">{{ stat.label }}</span>
                    <component
                        :is="stat.icon"
                        :class="['size-5', stat.color]"
                    />
                </div>
                <p class="mt-3 text-2xl font-semibold tabular-nums">
                    {{ stat.value }}
                </p>
            </div>
        </section>

        <!-- Modules Grid -->
        <section>
            <h2 class="mb-4 text-lg font-semibold">Modules</h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="module in modules"
                    :key="module.title"
                    :href="module.href"
                    class="group bg-card relative overflow-hidden rounded-xl border p-5 transition-all hover:border-violet-500/30 hover:shadow-lg hover:shadow-violet-500/5"
                >
                    <div class="flex items-start gap-4">
                        <div :class="['flex size-10 shrink-0 items-center justify-center rounded-lg', module.color]">
                            <component
                                :is="module.icon"
                                class="size-5"
                            />
                        </div>
                        <div class="flex-1">
                            <h3 class="font-semibold">{{ module.title }}</h3>
                            <p class="text-muted-foreground mt-1 line-clamp-2 text-sm">
                                {{ module.description }}
                            </p>
                        </div>
                    </div>
                </Link>
            </div>
        </section>
    </div>
</template>
