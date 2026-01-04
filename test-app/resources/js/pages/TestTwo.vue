<template>
    <section class="space-y-4">
        <p class="text-xs uppercase tracking-[0.2em] text-muted-foreground">
            Test Page Two
        </p>
        <h1 class="text-2xl font-semibold">Second Test Page</h1>
        <p class="text-sm text-muted-foreground">
            Another simple route to confirm the router history works.
        </p>
        <RouterLink
            to="/"
            class="inline-flex items-center gap-2 text-sm font-medium text-primary hover:underline"
        >
            Back to Welcome
        </RouterLink>
    </section>
</template>
