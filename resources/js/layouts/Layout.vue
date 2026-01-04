<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    FileText,
    Home,
    Layers,
    Laptop,
    Moon,
    RefreshCw,
    Settings,
    Sun,
    UploadCloud,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { useAppearance } from '@/composables/useAppearance';
import { cn, urlIsActive } from '@/lib/utils';

const page = usePage();
const features = computed(() => page.props.vox?.features ?? {});
const { appearance, updateAppearance } = useAppearance();

const themeOptions = [
    { value: 'light', label: 'Light', icon: Sun },
    { value: 'dark', label: 'Dark', icon: Moon },
    { value: 'system', label: 'System', icon: Laptop },
] as const;

const navigation = computed(() =>
    [
        { label: 'Dashboard', href: '/vox', icon: Home, feature: 'dashboard' },
        { label: 'Sync', href: '/vox/sync', icon: RefreshCw, feature: 'sync' },
        { label: 'Manage', href: '/vox/manage', icon: Layers, feature: 'manage' },
        {
            label: 'Publish',
            href: '/vox/publish',
            icon: UploadCloud,
            feature: 'publish',
        },
        { label: 'Audit', href: '/vox/audit', icon: FileText, feature: 'audit' },
        {
            label: 'Settings',
            href: '/vox/settings',
            icon: Settings,
            feature: 'settings',
        },
    ].filter((item) => features.value[item.feature] !== false),
);
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <header class="border-b bg-card/60">
            <div
                class="mx-auto flex w-full max-w-5xl flex-col gap-4 px-4 py-4 md:flex-row md:items-center md:justify-between md:px-6"
            >
                <div class="flex items-center gap-3">
                    <div
                        class="flex size-9 items-center justify-center rounded-lg bg-primary text-sm font-semibold text-primary-foreground"
                    >
                        VX
                    </div>
                    <div>
                        <p class="text-sm font-semibold tracking-wide">Laravel Vox</p>
                        <p class="text-xs text-muted-foreground">
                            Package Dashboard
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-3 md:justify-end">
                    <nav class="flex flex-wrap items-center gap-2 text-sm">
                        <Link
                            v-for="item in navigation"
                            :key="item.href"
                            :href="item.href"
                            :title="item.label"
                            :class="
                                cn(
                                    'inline-flex items-center gap-2 rounded-md px-3 py-1.5 text-muted-foreground transition hover:bg-muted hover:text-foreground',
                                    urlIsActive(item.href, page.url) &&
                                        'bg-muted text-foreground',
                                )
                            "
                        >
                            <component :is="item.icon" class="size-4" />
                            <span class="hidden sm:inline">{{ item.label }}</span>
                        </Link>
                    </nav>
                    <div
                        class="flex items-center rounded-full border border-border/70 bg-background/70 p-1 text-xs"
                    >
                        <button
                            v-for="option in themeOptions"
                            :key="option.value"
                            type="button"
                            :aria-pressed="appearance === option.value"
                            :class="
                                cn(
                                    'inline-flex items-center gap-1 rounded-full px-2.5 py-1 transition',
                                    appearance === option.value
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground hover:text-foreground',
                                )
                            "
                            @click="updateAppearance(option.value)"
                        >
                            <component :is="option.icon" class="size-3.5" />
                            <span class="hidden sm:inline">{{
                                option.label
                            }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </header>
        <main class="mx-auto w-full max-w-5xl px-4 py-10 sm:px-6">
            <slot />
        </main>
    </div>
</template>
