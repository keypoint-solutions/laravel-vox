<script setup lang="ts">
    import { Link, usePage } from '@inertiajs/vue3';
    import {
        FileText,
        GitBranch,
        Home,
        Laptop,
        Layers,
        Menu,
        Moon,
        RefreshCw,
        Settings,
        Sun,
        UploadCloud,
        X,
    } from '@lucide/vue';
    import { computed, ref } from 'vue';

    import { Tooltip } from '@/components/ui';
    import { useAppearance } from '@/composables/useAppearance';
    import { cn, urlIsActive } from '@/lib/utils';

    const page = usePage();
    const features = computed(() => page.props.vox?.features ?? {});
    const routes = computed(() => page.props.vox?.routes);
    const { appearance, updateAppearance } = useAppearance();
    const mobileMenuOpen = ref(false);

    const themeOptions = [
        { value: 'light', label: 'Light', icon: Sun },
        { value: 'dark', label: 'Dark', icon: Moon },
        { value: 'system', label: 'System', icon: Laptop },
    ] as const;

    const navigation = computed(() =>
        [
            { label: 'Dashboard', href: routes.value?.dashboard ?? '', icon: Home, feature: 'dashboard' },
            { label: 'Sync', href: routes.value?.sync ?? '', icon: RefreshCw, feature: 'sync' },
            { label: 'Manage', href: routes.value?.manage ?? '', icon: Layers, feature: 'manage' },
            {
                label: 'Publish',
                href: routes.value?.publish ?? '',
                icon: UploadCloud,
                feature: 'publish',
            },
            { label: 'Audit', href: routes.value?.audit ?? '', icon: FileText, feature: 'audit' },
            {
                label: 'Settings',
                href: routes.value?.settings ?? '',
                icon: Settings,
                feature: 'settings',
            },
        ].filter((item) => item.href && features.value[item.feature] !== false)
    );

    function closeMobileMenu() {
        mobileMenuOpen.value = false;
    }
</script>

<template>
    <div class="bg-background text-foreground flex min-h-screen">
        <!-- Desktop Sidebar -->
        <aside
            class="border-sidebar-border bg-sidebar fixed inset-y-0 left-0 z-50 hidden w-56 flex-col border-r lg:flex"
        >
            <!-- Logo -->
            <div class="border-sidebar-border flex h-16 items-center gap-3 border-b px-4">
                <div
                    class="flex size-9 items-center justify-center rounded-lg bg-violet-500 text-sm font-bold text-white"
                >
                    VX
                </div>
                <div>
                    <p class="text-sidebar-foreground text-sm font-semibold">Laravel Vox</p>
                    <p class="text-sidebar-foreground/60 text-xs">Translation Manager</p>
                </div>
            </div>

            <!-- Navigation -->
            <nav class="flex-1 space-y-1 px-3 py-4">
                <Link
                    v-for="item in navigation"
                    :key="item.href"
                    :href="item.href"
                    :class="
                        cn(
                            'flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors',
                            urlIsActive(item.href, page.url)
                                ? 'bg-violet-500/10 text-violet-400'
                                : 'text-sidebar-foreground/70 hover:bg-sidebar-accent hover:text-sidebar-foreground'
                        )
                    "
                >
                    <component
                        :is="item.icon"
                        class="size-5"
                    />
                    {{ item.label }}
                </Link>
            </nav>

            <!-- Theme Switcher -->
            <div class="border-sidebar-border border-t p-4">
                <div class="bg-sidebar-accent flex items-center justify-center gap-1 rounded-lg p-1">
                    <Tooltip
                        v-for="option in themeOptions"
                        :key="option.value"
                        :text="`${option.label} theme`"
                        class="flex-1"
                    >
                        <button
                            type="button"
                            :aria-label="`${option.label} theme`"
                            :aria-pressed="appearance === option.value"
                            :class="
                                cn(
                                    'flex w-full items-center justify-center rounded-md p-2 transition-colors',
                                    appearance === option.value
                                        ? 'bg-violet-500 text-white'
                                        : 'text-sidebar-foreground/60 hover:text-sidebar-foreground'
                                )
                            "
                            @click="updateAppearance(option.value)"
                        >
                            <component
                                :is="option.icon"
                                class="size-4"
                            />
                        </button>
                    </Tooltip>
                </div>
                <div class="text-sidebar-foreground/55 mt-4 space-y-2 text-xs leading-relaxed">
                    <p>
                        An open-source project offered by
                        <a
                            class="text-sidebar-foreground/80 hover:text-sidebar-foreground font-medium"
                            href="https://keypoint.ro"
                            rel="noreferrer"
                            target="_blank"
                        >
                            Keypoint Solutions
                        </a>
                    </p>
                    <a
                        class="text-sidebar-foreground/70 hover:text-sidebar-foreground inline-flex items-center gap-1.5"
                        href="https://github.com/keypoint-solutions/laravel-vox"
                        rel="noreferrer"
                        target="_blank"
                    >
                        <GitBranch class="size-3.5" />
                        View on GitHub
                    </a>
                </div>
            </div>
        </aside>

        <!-- Mobile Header -->
        <header
            class="border-border bg-background fixed inset-x-0 top-0 z-40 flex h-16 items-center justify-between border-b px-4 lg:hidden"
        >
            <div class="flex items-center gap-3">
                <div
                    class="flex size-8 items-center justify-center rounded-lg bg-violet-500 text-xs font-bold text-white"
                >
                    VX
                </div>
                <span class="text-sm font-semibold">Laravel Vox</span>
            </div>
            <Tooltip :text="mobileMenuOpen ? 'Close navigation' : 'Open navigation'">
                <button
                    type="button"
                    :aria-label="mobileMenuOpen ? 'Close navigation' : 'Open navigation'"
                    class="text-muted-foreground hover:bg-muted hover:text-foreground rounded-lg p-2"
                    @click="mobileMenuOpen = !mobileMenuOpen"
                >
                    <Menu
                        v-if="!mobileMenuOpen"
                        class="size-5"
                    />
                    <X
                        v-else
                        class="size-5"
                    />
                </button>
            </Tooltip>
        </header>

        <!-- Mobile Menu Overlay -->
        <Transition
            enter-active-class="transition-opacity duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-200"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="mobileMenuOpen"
                class="fixed inset-0 z-40 bg-black/50 lg:hidden"
                @click="closeMobileMenu"
            />
        </Transition>

        <!-- Mobile Menu -->
        <Transition
            enter-active-class="transition-transform duration-200"
            enter-from-class="-translate-x-full"
            enter-to-class="translate-x-0"
            leave-active-class="transition-transform duration-200"
            leave-from-class="translate-x-0"
            leave-to-class="-translate-x-full"
        >
            <aside
                v-if="mobileMenuOpen"
                class="border-sidebar-border bg-sidebar fixed inset-y-0 left-0 z-50 flex w-64 flex-col border-r lg:hidden"
            >
                <!-- Logo -->
                <div class="border-sidebar-border flex h-16 items-center justify-between border-b px-4">
                    <div class="flex items-center gap-3">
                        <div
                            class="flex size-9 items-center justify-center rounded-lg bg-violet-500 text-sm font-bold text-white"
                        >
                            VX
                        </div>
                        <div>
                            <p class="text-sidebar-foreground text-sm font-semibold">Laravel Vox</p>
                            <p class="text-sidebar-foreground/60 text-xs">Translation Manager</p>
                        </div>
                    </div>
                    <Tooltip text="Close navigation">
                        <button
                            type="button"
                            aria-label="Close navigation"
                            class="text-sidebar-foreground/60 hover:bg-sidebar-accent hover:text-sidebar-foreground rounded-lg p-1.5"
                            @click="closeMobileMenu"
                        >
                            <X class="size-5" />
                        </button>
                    </Tooltip>
                </div>

                <!-- Navigation -->
                <nav class="flex-1 space-y-1 px-3 py-4">
                    <Link
                        v-for="item in navigation"
                        :key="item.href"
                        :href="item.href"
                        :class="
                            cn(
                                'flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors',
                                urlIsActive(item.href, page.url)
                                    ? 'bg-violet-500/10 text-violet-400'
                                    : 'text-sidebar-foreground/70 hover:bg-sidebar-accent hover:text-sidebar-foreground'
                            )
                        "
                        @click="closeMobileMenu"
                    >
                        <component
                            :is="item.icon"
                            class="size-5"
                        />
                        {{ item.label }}
                    </Link>
                </nav>

                <!-- Theme Switcher -->
                <div class="border-sidebar-border border-t p-4">
                    <div class="bg-sidebar-accent flex items-center justify-center gap-1 rounded-lg p-1">
                        <Tooltip
                            v-for="option in themeOptions"
                            :key="option.value"
                            :text="`${option.label} theme`"
                            class="flex-1"
                        >
                            <button
                                type="button"
                                :aria-label="`${option.label} theme`"
                                :aria-pressed="appearance === option.value"
                                :class="
                                    cn(
                                        'flex w-full items-center justify-center rounded-md p-2 transition-colors',
                                        appearance === option.value
                                            ? 'bg-violet-500 text-white'
                                            : 'text-sidebar-foreground/60 hover:text-sidebar-foreground'
                                    )
                                "
                                @click="updateAppearance(option.value)"
                            >
                                <component
                                    :is="option.icon"
                                    class="size-4"
                                />
                            </button>
                        </Tooltip>
                    </div>
                    <div class="text-sidebar-foreground/55 mt-4 space-y-2 text-xs leading-relaxed">
                        <p>
                            An open-source project offered by
                            <a
                                class="text-sidebar-foreground/80 hover:text-sidebar-foreground font-medium"
                                href="https://keypoint.ro"
                                rel="noreferrer"
                                target="_blank"
                            >
                                Keypoint Solutions
                            </a>
                        </p>
                        <a
                            class="text-sidebar-foreground/70 hover:text-sidebar-foreground inline-flex items-center gap-1.5"
                            href="https://github.com/keypoint-solutions/laravel-vox"
                            rel="noreferrer"
                            target="_blank"
                        >
                            <GitBranch class="size-3.5" />
                            View on GitHub
                        </a>
                    </div>
                </div>
            </aside>
        </Transition>

        <!-- Main Content -->
        <main class="flex-1 pt-16 lg:ml-56 lg:pt-0">
            <div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
                <slot />
            </div>
        </main>
    </div>
</template>
