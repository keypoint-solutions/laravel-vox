<template>
    <div class="min-h-screen bg-background text-foreground">
        <header class="border-b bg-card/70">
            <div
                class="mx-auto flex w-full max-w-4xl items-center justify-between px-6 py-4"
            >
                <div>
                    <p class="text-sm font-semibold">Laravel Vox Test App</p>
                    <p class="text-xs text-muted-foreground">
                        Simple Vue Router SPA
                    </p>
                </div>
                <nav class="flex items-center gap-2 text-sm">
                    <RouterLink
                        to="/"
                        class="rounded-md px-3 py-1.5 text-muted-foreground transition hover:bg-muted hover:text-foreground"
                        active-class="bg-muted text-foreground"
                        exact-active-class="bg-muted text-foreground"
                    >
                        Welcome
                    </RouterLink>
                    <RouterLink
                        to="/test-one"
                        class="rounded-md px-3 py-1.5 text-muted-foreground transition hover:bg-muted hover:text-foreground"
                        active-class="bg-muted text-foreground"
                    >
                        Test One
                    </RouterLink>
                    <RouterLink
                        to="/test-two"
                        class="rounded-md px-3 py-1.5 text-muted-foreground transition hover:bg-muted hover:text-foreground"
                        active-class="bg-muted text-foreground"
                    >
                        Test Two
                    </RouterLink>
                    <a
                        href="/vox"
                        class="rounded-md px-3 py-1.5 text-muted-foreground transition hover:bg-muted hover:text-foreground"
                    >
                        Vox Dashboard
                    </a>
                </nav>
            </div>
        </header>
        <main class="mx-auto w-full max-w-4xl px-6 py-10">
            <RouterView />
        </main>
    </div>
</template>
