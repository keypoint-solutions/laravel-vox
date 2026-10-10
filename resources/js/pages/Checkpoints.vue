<script setup lang="ts">
    import { Head, router, useForm, usePage } from '@inertiajs/vue3';
    import { ref } from 'vue';

    import { Badge, Button, Input } from '@/components/ui';
    import PaginationNav from '@/components/ui/PaginationNav.vue';
    import { useDateTime } from '@/composables/useDateTime';
    import { useVoxRoutes } from '@/composables/useVoxRoutes';
    import Layout from '@/layouts/Layout.vue';
    import { firstError, routeUrl } from '@/lib/inertia';

    defineOptions({ layout: Layout });

    interface Checkpoint {
        id: number;
        label: string;
        is_manual: boolean;
        created_at: string;
        rows: number;
        files: number;
    }

    const page = usePage<{
        available: boolean;
        checkpoints: { data: Checkpoint[]; current_page: number; last_page: number } | null;
    }>();
    const routes = useVoxRoutes();
    const form = useForm({ label: '' });
    const selected = ref<Checkpoint | null>(null);
    const restoring = ref(false);
    const error = ref('');
    const { formatDateTime } = useDateTime();

    function create(): void {
        form.post(routes.value?.checkpoints_store ?? '', { preserveScroll: true, onSuccess: () => form.reset() });
    }

    function restore(): void {
        if (!selected.value) {
            return;
        }
        restoring.value = true;
        error.value = '';
        router.post(
            routeUrl(routes.value?.checkpoints_restore, 'checkpoint', selected.value.id),
            { confirm: true },
            {
                preserveScroll: true,
                onError: (errors) => {
                    error.value = firstError(errors, 'Unable to restore checkpoint.');
                },
                onSuccess: () => {
                    selected.value = null;
                },
                onFinish: () => {
                    restoring.value = false;
                },
            }
        );
    }

    function goToPage(number: number): void {
        router.get(routes.value?.checkpoints ?? '', { page: number });
    }
</script>

<template>
    <Head title="Checkpoints" />
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold">Translation checkpoints</h1>
            <p class="text-muted-foreground mt-2 text-sm">
                Return translations to an earlier point, including drafts, approvals, published overrides, and language
                files. Vox automatically records changes made through the manager and translation commands.
            </p>
            <p class="text-muted-foreground mt-2 text-sm">
                Manual checkpoints mark a point in time without copying your translations. Only changed data is stored.
                Changes made directly to files or the database outside Vox are not recorded. Resetting Vox data clears
                checkpoint history.
            </p>
        </div>
        <p
            v-if="!page.props.available"
            class="rounded-lg border p-4"
        >
            Run vox:setup to enable checkpoints.
        </p>
        <template v-else>
            <p
                v-if="page.flash?.success"
                role="status"
                class="rounded-lg border border-emerald-500/30 p-4 text-sm"
            >
                {{ page.flash.success }}
            </p>
            <form
                class="flex flex-wrap items-end gap-3 rounded-lg border p-4"
                @submit.prevent="create"
            >
                <div class="min-w-48 flex-1 space-y-2">
                    <label
                        for="checkpoint-label"
                        class="text-sm font-medium"
                        >Checkpoint name</label
                    >
                    <Input
                        id="checkpoint-label"
                        v-model="form.label"
                        placeholder="Before reviewing French translations"
                        maxlength="200"
                    />
                    <p
                        v-if="form.errors.label"
                        class="text-destructive text-sm"
                    >
                        {{ form.errors.label }}
                    </p>
                </div>
                <Button
                    type="submit"
                    :disabled="form.processing || !form.label.trim()"
                    >Create checkpoint</Button
                >
            </form>
            <div
                v-if="selected"
                role="region"
                aria-label="Restore checkpoint"
                class="space-y-3 rounded-lg border border-amber-500/40 p-4"
            >
                <h2 class="font-semibold">Restore “{{ selected.label }}”?</h2>
                <p class="text-sm">
                    All recorded translation changes from this point onward will be undone, including live language
                    files. Manager preferences and credentials are not restored. A new checkpoint will let you undo this
                    rollback.
                </p>
                <p
                    v-if="error"
                    role="alert"
                    class="text-destructive text-sm"
                >
                    {{ error }}
                </p>
                <div class="flex gap-2">
                    <Button
                        :disabled="restoring"
                        @click="restore"
                        >{{ restoring ? 'Restoring…' : 'Confirm restore' }}</Button
                    >
                    <Button
                        variant="outline"
                        :disabled="restoring"
                        @click="selected = null"
                        >Cancel</Button
                    >
                </div>
            </div>
            <div class="divide-y rounded-lg border">
                <p
                    v-if="!page.props.checkpoints?.data.length"
                    class="text-muted-foreground p-6 text-sm"
                >
                    No checkpoints yet. Create one now, or make a translation change.
                </p>
                <div
                    v-for="checkpoint in page.props.checkpoints?.data"
                    :key="checkpoint.id"
                    class="flex flex-wrap items-center justify-between gap-3 p-4"
                >
                    <div class="space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="font-medium">{{ checkpoint.label }}</h2>
                            <Badge variant="secondary">{{ checkpoint.is_manual ? 'Manual' : 'Automatic' }}</Badge>
                        </div>
                        <p class="text-muted-foreground text-xs">
                            #{{ checkpoint.id }} · {{ formatDateTime(checkpoint.created_at) }} ·
                            {{ checkpoint.rows }} changed {{ checkpoint.rows === 1 ? 'record' : 'records' }} ·
                            {{ checkpoint.files }} changed {{ checkpoint.files === 1 ? 'file' : 'files' }}
                        </p>
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        :aria-label="`Restore checkpoint ${checkpoint.id}`"
                        :disabled="restoring"
                        @click="
                            selected = checkpoint;
                            error = '';
                        "
                        >Restore</Button
                    >
                </div>
            </div>
            <div
                v-if="page.props.checkpoints"
                class="flex items-center justify-between"
            >
                <PaginationNav
                    variant="text"
                    :current-page="page.props.checkpoints.current_page"
                    :last-page="page.props.checkpoints.last_page"
                    @change="goToPage"
                >
                    <span class="text-muted-foreground text-sm"
                        >Page {{ page.props.checkpoints.current_page }} of {{ page.props.checkpoints.last_page }}</span
                    >
                </PaginationNav>
            </div>
        </template>
    </div>
</template>
