<template>
    <section class="space-y-4">
        <p class="text-xs uppercase tracking-[0.2em] text-muted-foreground">
            Test Page One
        </p>
        <h1 class="text-2xl font-semibold">First Test Page</h1>
        <p class="text-sm text-muted-foreground">
            This is a lightweight route to validate SPA navigation.
        </p>
        <RouterLink
            to="/test-two"
            class="inline-flex items-center gap-2 text-sm font-medium text-primary hover:underline"
        >
            Jump to Test Two
        </RouterLink>
    </section>
</template>
