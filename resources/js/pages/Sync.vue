<script setup lang="ts">
    import { Head, router, useForm, usePage } from '@inertiajs/vue3';
    import { Check, KeyRound, Pencil, Plus, RefreshCw, Server, Trash2 } from '@lucide/vue';
    import { computed, ref } from 'vue';

    import { Badge, Button, Input, Label, Tooltip } from '@/components/ui';
    import { useDateTime } from '@/composables/useDateTime';
    import Layout from '@/layouts/Layout.vue';

    defineOptions({
        layout: Layout,
    });

    interface EnvironmentItem {
        id: number;
        name: string;
        type: string;
        url: string;
        secret_key_set: boolean;
        updated_at: string | null;
    }

    interface SyncPageProps {
        environments: EnvironmentItem[];
        lastSyncAt: string | null;
    }

    const page = usePage<SyncPageProps>();
    const { formatDateTime } = useDateTime();
    const environments = computed(() => page.props.environments ?? []);
    const routes = computed(() => page.props.vox?.routes);
    const editingId = ref<number | null>(null);
    const isSyncingLocal = ref(false);
    const pullingId = ref<number | null>(null);
    const success = ref<string | null>(null);
    const actionError = ref<string | null>(null);
    const form = useForm({
        name: '',
        type: 'staging',
        url: '',
        secret_key: '',
    });

    function environmentRoute(template: string | undefined, id: number): string {
        return template?.replace('__environment__', String(id)) ?? '';
    }

    function resetForm(): void {
        editingId.value = null;
        form.reset();
        form.type = 'staging';
        form.clearErrors();
    }

    function edit(environment: EnvironmentItem): void {
        editingId.value = environment.id;
        form.name = environment.name;
        form.type = environment.type;
        form.url = environment.url;
        form.secret_key = '';
        form.clearErrors();
        success.value = null;
        actionError.value = null;
    }

    function submit(): void {
        success.value = null;
        actionError.value = null;

        const options = {
            preserveScroll: true,
            onSuccess: (responsePage: typeof page) => {
                success.value = (responsePage.flash?.success as string | undefined) ?? 'Environment saved.';
                resetForm();
            },
        };

        if (editingId.value !== null) {
            form.put(environmentRoute(routes.value?.sync_environment_update, editingId.value), options);

            return;
        }

        form.post(routes.value?.sync_environment_store ?? '', options);
    }

    function pull(environment: EnvironmentItem): void {
        pullingId.value = environment.id;
        success.value = null;
        actionError.value = null;

        router.post(
            environmentRoute(routes.value?.sync_environment_pull, environment.id),
            {},
            {
                preserveScroll: true,
                onError: (errors) => {
                    actionError.value = Object.values(errors)[0] ?? 'Remote sync failed.';
                },
                onSuccess: (responsePage) => {
                    success.value =
                        (responsePage.flash?.success as string | undefined) ?? 'Remote translations synchronized.';
                },
                onFinish: () => {
                    pullingId.value = null;
                },
            }
        );
    }

    function syncLocal(): void {
        isSyncingLocal.value = true;
        success.value = null;
        actionError.value = null;

        router.post(
            routes.value?.sync_local ?? '',
            {},
            {
                preserveScroll: true,
                onError: (errors) => {
                    actionError.value = Object.values(errors)[0] ?? 'Local sync failed.';
                },
                onSuccess: (responsePage) => {
                    success.value =
                        (responsePage.flash?.success as string | undefined) ?? 'Local translations synchronized.';
                },
                onFinish: () => {
                    isSyncingLocal.value = false;
                },
            }
        );
    }

    function remove(environment: EnvironmentItem): void {
        if (!window.confirm(`Remove ${environment.name}?`)) {
            return;
        }

        router.delete(environmentRoute(routes.value?.sync_environment_destroy, environment.id), {
            preserveScroll: true,
            onSuccess: (responsePage) => {
                success.value = (responsePage.flash?.success as string | undefined) ?? 'Environment removed.';

                if (editingId.value === environment.id) {
                    resetForm();
                }
            },
        });
    }
</script>

<template>
    <Head title="Sync" />

    <div class="space-y-6">
        <header>
            <p class="text-muted-foreground text-xs font-medium tracking-[0.2em] uppercase">Remote environments</p>
            <h1 class="mt-2 text-2xl font-semibold">Synchronize translations</h1>
            <p class="text-muted-foreground mt-2 max-w-2xl text-sm">
                Pull a keyed language archive from another Vox application, validate it, merge it into this app, and
                refresh the management database.
            </p>
        </header>

        <div
            v-if="success"
            role="status"
            aria-live="polite"
            class="flex items-center gap-2 rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm text-emerald-600"
        >
            <Check class="size-4 shrink-0" />
            {{ success }}
        </div>

        <div
            v-if="actionError"
            role="alert"
            class="text-destructive border-destructive/40 bg-destructive/10 rounded-lg border p-3 text-sm"
        >
            {{ actionError }}
        </div>

        <section class="bg-card flex flex-col gap-4 rounded-xl border p-5 sm:flex-row sm:items-center">
            <div class="flex min-w-0 flex-1 items-start gap-3">
                <div class="bg-primary/10 text-primary flex size-9 shrink-0 items-center justify-center rounded-lg">
                    <RefreshCw :class="['size-4', isSyncingLocal && 'animate-spin']" />
                </div>
                <div>
                    <h2 class="text-sm font-semibold">Local files to database</h2>
                    <p class="text-muted-foreground mt-1 text-sm">
                        Scan this application's source and language files, then refresh Vox's review database.
                    </p>
                </div>
            </div>
            <Button
                :disabled="isSyncingLocal || pullingId !== null"
                variant="outline"
                @click="syncLocal"
            >
                {{ isSyncingLocal ? 'Synchronizing…' : 'Sync local files' }}
            </Button>
        </section>

        <section class="bg-card rounded-xl border">
            <div class="flex flex-col gap-2 border-b p-5 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-sm font-semibold">Configured environments</h2>
                    <p class="text-muted-foreground mt-1 text-xs">
                        Pull production or staging values into local files for merging. Last successful sync:
                        {{ formatDateTime(page.props.lastSyncAt, 'Not synced yet') }}
                    </p>
                </div>
                <Badge variant="secondary">{{ environments.length }} configured</Badge>
            </div>

            <div
                v-if="environments.length === 0"
                class="p-8 text-center"
            >
                <Server class="text-muted-foreground/50 mx-auto size-9" />
                <p class="mt-3 text-sm font-medium">No remote environments yet</p>
                <p class="text-muted-foreground mt-1 text-xs">Add the headless demo fixture or another Vox endpoint.</p>
            </div>

            <div
                v-for="environment in environments"
                v-else
                :key="environment.id"
                class="flex flex-col gap-4 border-b p-4 last:border-b-0 sm:flex-row sm:items-center"
            >
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="truncate text-sm font-semibold">{{ environment.name }}</p>
                        <Badge variant="outline">{{ environment.type }}</Badge>
                        <span
                            v-if="environment.secret_key_set"
                            class="text-muted-foreground inline-flex items-center gap-1 text-xs"
                        >
                            <KeyRound class="size-3" />
                            Key configured
                        </span>
                    </div>
                    <p class="text-muted-foreground mt-1 truncate text-xs">{{ environment.url }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <Button
                        :disabled="pullingId !== null"
                        size="sm"
                        @click="pull(environment)"
                    >
                        <RefreshCw :class="['size-4', pullingId === environment.id && 'animate-spin']" />
                        {{ pullingId === environment.id ? 'Pulling…' : 'Pull now' }}
                    </Button>
                    <Tooltip text="Edit environment">
                        <Button
                            aria-label="Edit environment"
                            size="icon"
                            variant="ghost"
                            @click="edit(environment)"
                        >
                            <Pencil class="size-4" />
                        </Button>
                    </Tooltip>
                    <Tooltip text="Remove environment">
                        <Button
                            aria-label="Remove environment"
                            size="icon"
                            variant="ghost"
                            @click="remove(environment)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </Tooltip>
                </div>
            </div>
        </section>

        <section class="bg-card rounded-xl border p-5">
            <div class="mb-5 flex items-start gap-3">
                <div class="bg-primary/10 text-primary flex size-9 items-center justify-center rounded-lg">
                    <Plus class="size-4" />
                </div>
                <div>
                    <h2 class="text-sm font-semibold">
                        {{ editingId === null ? 'Add an environment' : 'Edit environment' }}
                    </h2>
                    <p class="text-muted-foreground mt-1 text-xs">
                        Use either the application URL or its complete Vox sync endpoint.
                    </p>
                </div>
            </div>

            <form
                class="grid gap-4 sm:grid-cols-2"
                @submit.prevent="submit"
            >
                <div class="space-y-1.5">
                    <Label for="sync-name">Name</Label>
                    <Input
                        id="sync-name"
                        v-model="form.name"
                        autocomplete="off"
                        placeholder="Demo remote"
                    />
                    <p
                        v-if="form.errors.name"
                        class="text-destructive text-xs"
                    >
                        {{ form.errors.name }}
                    </p>
                </div>
                <div class="space-y-1.5">
                    <Label for="sync-type">Environment type</Label>
                    <select
                        id="sync-type"
                        v-model="form.type"
                        class="border-input bg-background h-10 w-full rounded-lg border px-3 text-sm"
                    >
                        <option value="testing">Testing</option>
                        <option value="staging">Staging</option>
                        <option value="production">Production</option>
                        <option value="custom">Custom</option>
                    </select>
                </div>
                <div class="space-y-1.5 sm:col-span-2">
                    <Label for="sync-url">URL</Label>
                    <Input
                        id="sync-url"
                        v-model="form.url"
                        inputmode="url"
                        placeholder="https://staging.example.com"
                    />
                    <p
                        v-if="form.errors.url"
                        class="text-destructive text-xs"
                    >
                        {{ form.errors.url }}
                    </p>
                </div>
                <div class="space-y-1.5 sm:col-span-2">
                    <Label for="sync-key">Sync key</Label>
                    <Input
                        id="sync-key"
                        v-model="form.secret_key"
                        autocomplete="new-password"
                        :placeholder="editingId === null ? 'Required' : 'Leave blank to keep the current key'"
                        type="password"
                    />
                    <p
                        v-if="form.errors.secret_key"
                        class="text-destructive text-xs"
                    >
                        {{ form.errors.secret_key }}
                    </p>
                </div>
                <div class="flex items-center justify-end gap-2 sm:col-span-2">
                    <Button
                        v-if="editingId !== null"
                        type="button"
                        variant="outline"
                        @click="resetForm"
                    >
                        Cancel
                    </Button>
                    <Button
                        :disabled="form.processing"
                        type="submit"
                    >
                        {{ form.processing ? 'Saving…' : editingId === null ? 'Add environment' : 'Save environment' }}
                    </Button>
                </div>
            </form>
        </section>
    </div>
</template>
