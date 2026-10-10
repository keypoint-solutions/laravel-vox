<script setup lang="ts">
    import { Head, router, useForm, usePage } from '@inertiajs/vue3';
    import {
        Download,
        FileArchive,
        KeyRound,
        Languages,
        Pencil,
        Plus,
        RefreshCw,
        Server,
        Trash2,
        Upload,
    } from '@lucide/vue';
    import { computed, markRaw, nextTick, ref } from 'vue';

    import RemoteReconciliationPanel, { type ReconciliationPage } from '@/components/RemoteReconciliationPanel.vue';
    import { Badge, Button, Input, Label, SlidePanel, Tooltip } from '@/components/ui';
    import Alert from '@/components/ui/Alert.vue';
    import { useDateTime } from '@/composables/useDateTime';
    import { useVoxRoutes } from '@/composables/useVoxRoutes';
    import Layout from '@/layouts/Layout.vue';
    import { firstError, flashSuccess, routeUrl } from '@/lib/inertia';

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

    type SyncPageProps = {
        reconciliation: ReconciliationPage;
        environments: EnvironmentItem[];
        lastSyncAt: string | null;
        locales: {
            code: string;
            name: string;
            is_default: boolean;
            has_runtime_translations: boolean;
        }[];
        localeRemovalReasons: Record<string, string | null>;
        baseLocale: string;
        ai: {
            available: boolean;
            can_choose: boolean;
        };
    };

    const page = usePage<SyncPageProps>();
    const { formatDateTime } = useDateTime();
    const environments = computed(() => page.props.environments ?? []);
    const routes = useVoxRoutes();
    const editingId = ref<number | null>(null);
    const isSyncingLocal = ref(false);
    const pullingId = ref<number | null>(null);
    const archiveInput = ref<HTMLInputElement | null>(null);
    const isImportingArchive = ref(false);
    const success = ref<string | null>(null);
    const actionError = ref<string | null>(null);
    const form = useForm({
        name: '',
        type: 'staging',
        url: '',
        secret_key: '',
    });
    const archiveForm = useForm<{ archive: File | null }>({
        archive: null,
    });
    const localeForm = useForm({
        locale: '',
        auto_translate: false,
        use_default: false,
    });

    const removingLocale = ref<SyncPageProps['locales'][number] | null>(null);
    const removalForm = useForm({ locale: '' });

    function reviewLocaleRemoval(locale: SyncPageProps['locales'][number]): void {
        removalForm.reset();
        removalForm.clearErrors();
        removalForm.locale = locale.code;
        removingLocale.value = locale;
    }

    function removeLocale(): void {
        success.value = null;
        actionError.value = null;
        removalForm.delete(routes.value?.sync_locale_destroy ?? '', {
            preserveScroll: true,
            onSuccess: (responsePage) => {
                success.value = flashSuccess(responsePage, 'Language removed.');
                removingLocale.value = null;
                removalForm.reset();
            },
        });
    }

    function environmentRoute(template: string | undefined, id: number): string {
        return routeUrl(template, 'environment', id);
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
            onSuccess: (responsePage: { flash?: Record<string, unknown> }) => {
                success.value = flashSuccess(responsePage, 'Environment saved.');
                resetForm();
            },
        };

        if (editingId.value !== null) {
            form.put(environmentRoute(routes.value?.sync_environment_update, editingId.value), options);

            return;
        }

        form.post(routes.value?.sync_environment_store ?? '', options);
    }

    function showIncoming(sourceId: number): void {
        router.get(
            routes.value?.sync ?? '',
            { environment_id: sourceId, state: 'review', review_page: 1 },
            {
                preserveState: true,
                preserveScroll: true,
                onSuccess: async () => {
                    await nextTick();
                    document.getElementById('remote-review')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                },
            }
        );
    }

    function pull(environment: EnvironmentItem, includeDrafts = false): void {
        pullingId.value = environment.id;
        success.value = null;
        actionError.value = null;

        router.post(
            environmentRoute(routes.value?.sync_environment_pull, environment.id),
            { include_drafts: includeDrafts },
            {
                preserveScroll: true,
                onError: (errors) => {
                    actionError.value = firstError(errors, 'Remote sync failed.');
                },
                onSuccess: (responsePage) => {
                    success.value = flashSuccess(responsePage, 'Remote translations synchronized.');
                    showIncoming(environment.id);
                },
                onFinish: () => {
                    pullingId.value = null;
                },
            }
        );
    }

    function syncLocal(updateLanguageFiles: boolean): void {
        isSyncingLocal.value = true;
        success.value = null;
        actionError.value = null;

        router.post(
            routes.value?.sync_local ?? '',
            { update_language_files: updateLanguageFiles },
            {
                preserveScroll: true,
                onError: (errors) => {
                    actionError.value = firstError(errors, 'Local sync failed.');
                },
                onSuccess: (responsePage) => {
                    success.value = flashSuccess(responsePage, 'Local translations synchronized.');
                    showIncoming(-1);
                },
                onFinish: () => {
                    isSyncingLocal.value = false;
                },
            }
        );
    }

    function provisionLocale(): void {
        success.value = null;
        actionError.value = null;

        localeForm.post(routes.value?.sync_locale_store ?? '', {
            preserveScroll: true,
            onError: (errors) => {
                actionError.value = firstError(errors, 'Language provisioning failed.');
            },
            onSuccess: (responsePage) => {
                success.value = flashSuccess(responsePage, 'Language added.');
                localeForm.reset();
            },
        });
    }

    function selectArchive(event: Event): void {
        const file = (event.target as HTMLInputElement).files?.[0];
        archiveForm.archive = file ? markRaw(file) : null;
        archiveForm.clearErrors();
        success.value = null;
        actionError.value = null;
    }

    function downloadArchive(): void {
        window.location.assign(routes.value?.sync_archive_download ?? '');
    }

    function importArchive(): void {
        success.value = null;
        actionError.value = null;
        archiveForm.clearErrors();

        const archive = archiveInput.value?.files?.[0];

        if (!archive) {
            archiveForm.setError('archive', 'Choose a ZIP archive to import.');

            return;
        }

        isImportingArchive.value = true;
        const payload = new FormData();
        payload.append('archive', archive);

        router.post(routes.value?.sync_archive_import ?? '', payload, {
            preserveScroll: true,
            onError: (errors) => {
                const message = errors.archive ?? 'Translation archive import failed.';
                archiveForm.setError('archive', message);
                actionError.value = message;
            },
            onSuccess: (responsePage) => {
                success.value = flashSuccess(responsePage, 'Translation archive imported.');
                archiveForm.reset();

                if (archiveInput.value) {
                    archiveInput.value.value = '';
                }
            },
            onFinish: () => {
                isImportingArchive.value = false;
            },
        });
    }

    function remove(environment: EnvironmentItem): void {
        if (!window.confirm(`Remove ${environment.name}?`)) {
            return;
        }

        router.delete(environmentRoute(routes.value?.sync_environment_destroy, environment.id), {
            preserveScroll: true,
            onSuccess: (responsePage) => {
                success.value = flashSuccess(responsePage, 'Environment removed.');

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
            <p class="text-muted-foreground text-xs font-medium tracking-[0.2em] uppercase">Translation sources</p>
            <h1 class="mt-2 text-2xl font-semibold">Synchronize translations</h1>
            <p class="text-muted-foreground mt-2 max-w-2xl text-sm">
                Compare translations from local files or another Vox application, then accept the wording you want and
                publish the selected changes when ready.
            </p>
        </header>

        <Alert
            v-if="success"
            tone="success"
        >
            {{ success }}
        </Alert>

        <Alert
            v-if="actionError"
            tone="error"
        >
            {{ actionError }}
        </Alert>

        <section class="bg-card flex flex-col gap-4 rounded-xl border p-5 sm:flex-row sm:items-center">
            <div class="flex min-w-0 flex-1 items-start gap-3">
                <div class="bg-primary/10 text-primary flex size-9 shrink-0 items-center justify-center rounded-lg">
                    <RefreshCw :class="['size-4', isSyncingLocal && 'animate-spin']" />
                </div>
                <div>
                    <h2 class="text-sm font-semibold">Local files to database</h2>
                    <p class="text-muted-foreground mt-1 text-sm">
                        Compare language files with the database, then review and accept the incoming differences.
                    </p>
                </div>
            </div>
            <Button
                data-test="sync-local-open"
                :disabled="isSyncingLocal || pullingId !== null"
                variant="outline"
                @click="syncLocal(false)"
            >
                {{ isSyncingLocal ? 'Synchronizing…' : 'Sync local files' }}
            </Button>
            <Tooltip text="Update language files from source keys first, then sync for review">
                <Button
                    data-test="sync-with-file-update"
                    :disabled="isSyncingLocal || pullingId !== null"
                    variant="ghost"
                    @click="syncLocal(true)"
                >
                    Parse and sync
                </Button>
            </Tooltip>
        </section>

        <section class="bg-card rounded-xl border">
            <div class="flex flex-col gap-3 border-b p-5 sm:flex-row sm:items-start sm:justify-between">
                <div class="flex items-start gap-3">
                    <div class="bg-primary/10 text-primary flex size-9 shrink-0 items-center justify-center rounded-lg">
                        <Languages class="size-4" />
                    </div>
                    <div>
                        <h2 class="text-sm font-semibold">Application languages</h2>
                        <p class="text-muted-foreground mt-1 max-w-2xl text-xs">
                            Create a new locale from {{ page.props.baseLocale }} source files, then import it into Vox
                            for review.
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-1.5">
                    <Badge
                        v-for="locale in page.props.locales"
                        :key="locale.code"
                        :variant="locale.is_default ? 'default' : 'secondary'"
                    >
                        {{ locale.name }} · {{ locale.code }}
                        <Tooltip
                            v-if="page.props.localeRemovalReasons[locale.code] === null"
                            :text="`Remove ${locale.name}`"
                        >
                            <button
                                type="button"
                                class="hover:bg-destructive/10 ml-1 rounded-sm p-1 focus-visible:outline-2"
                                :aria-label="`Remove ${locale.name} (${locale.code})`"
                                :data-test="`remove-locale-${locale.code}`"
                                :disabled="localeForm.processing || removalForm.processing"
                                @click="reviewLocaleRemoval(locale)"
                            >
                                <Trash2 class="size-3" />
                            </button>
                        </Tooltip>
                    </Badge>
                </div>
            </div>

            <form
                class="grid gap-4 p-5 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)_auto] sm:items-end"
                @submit.prevent="provisionLocale"
            >
                <div class="space-y-1.5">
                    <Label for="new-locale">New locale code</Label>
                    <Input
                        id="new-locale"
                        v-model="localeForm.locale"
                        autocomplete="off"
                        data-test="sync-locale-code"
                        placeholder="de or pt_BR"
                    />
                    <p
                        v-if="localeForm.errors.locale"
                        class="text-destructive text-xs"
                    >
                        {{ localeForm.errors.locale }}
                    </p>
                </div>

                <div class="space-y-2">
                    <label class="flex items-start gap-2 text-sm">
                        <input
                            v-model="localeForm.use_default"
                            type="checkbox"
                            class="mt-0.5 size-4"
                            data-test="sync-locale-use-default"
                        />
                        <span
                            ><span class="font-medium">Use default language wording</span
                            ><span class="text-muted-foreground mt-0.5 block text-xs"
                                >Follow {{ page.props.baseLocale }}, including new keys. Publish to create language
                                files. You can translate individual groups or keys later.</span
                            ></span
                        >
                    </label>
                    <label class="flex items-start gap-2 text-sm">
                        <input
                            v-model="localeForm.auto_translate"
                            class="border-input text-primary focus:ring-ring mt-0.5 size-4 rounded"
                            data-test="sync-locale-auto-translate"
                            :disabled="!page.props.ai.available || localeForm.use_default"
                            type="checkbox"
                        />
                        <span>
                            <span class="font-medium">Translate with AI now</span>
                            <span class="text-muted-foreground mt-0.5 block text-xs">
                                {{
                                    page.props.ai.available
                                        ? 'This may take several minutes. Generated file values will be available immediately.'
                                        : 'Configure an AI translation driver and credentials to enable this option.'
                                }}
                            </span>
                        </span>
                    </label>
                    <p
                        v-if="localeForm.errors.auto_translate"
                        class="text-destructive text-xs"
                    >
                        {{ localeForm.errors.auto_translate }}
                    </p>
                </div>

                <Button
                    data-test="sync-locale-submit"
                    :disabled="localeForm.processing || localeForm.locale.trim() === ''"
                    type="submit"
                >
                    <Plus class="size-4" />
                    {{ localeForm.processing ? 'Adding…' : 'Add language' }}
                </Button>
            </form>
        </section>

        <section class="bg-card overflow-hidden rounded-xl border">
            <div class="border-b p-5">
                <div class="flex items-start gap-3">
                    <div class="bg-primary/10 text-primary flex size-9 shrink-0 items-center justify-center rounded-lg">
                        <FileArchive class="size-4" />
                    </div>
                    <div>
                        <h2 class="text-sm font-semibold">Translation archive</h2>
                        <p class="text-muted-foreground mt-1 text-xs">
                            Move reviewed language files without coupling the transfer to publishing or database sync.
                        </p>
                    </div>
                </div>
            </div>

            <div class="grid divide-y sm:grid-cols-2 sm:divide-x sm:divide-y-0">
                <div class="space-y-4 p-5">
                    <div>
                        <h3 class="text-sm font-medium">Download publishable files</h3>
                        <p class="text-muted-foreground mt-1 text-xs">
                            Download approved wording with published fallback choices as a ZIP. Publish pending fallback
                            choices before exporting. Local files are not changed.
                        </p>
                    </div>
                    <Button
                        data-test="sync-archive-download"
                        type="button"
                        variant="outline"
                        @click="downloadArchive"
                    >
                        <Download class="size-4" />
                        Download ZIP
                    </Button>
                </div>

                <form
                    class="space-y-4 p-5"
                    enctype="multipart/form-data"
                    @submit.prevent="importArchive"
                >
                    <div>
                        <h3 class="text-sm font-medium">Import language files</h3>
                        <p class="text-muted-foreground mt-1 text-xs">
                            Validate and copy a Vox ZIP over the language directory. This does not run Local sync.
                        </p>
                    </div>
                    <div class="space-y-1.5">
                        <Label for="translation-archive">Translation ZIP</Label>
                        <input
                            id="translation-archive"
                            ref="archiveInput"
                            accept=".zip,application/zip"
                            class="border-input bg-background file:text-foreground file:bg-muted h-10 w-full rounded-lg border px-3 py-1.5 text-sm file:mr-3 file:rounded file:border-0 file:px-2 file:py-1"
                            data-test="sync-archive-file"
                            name="archive"
                            type="file"
                            @change="selectArchive"
                        />
                        <p
                            v-if="archiveForm.errors.archive"
                            class="text-destructive text-xs"
                        >
                            {{ archiveForm.errors.archive }}
                        </p>
                    </div>
                    <Button
                        data-test="sync-archive-import"
                        :disabled="isImportingArchive || archiveForm.archive === null"
                        type="submit"
                    >
                        <Upload class="size-4" />
                        {{ isImportingArchive ? 'Importing…' : 'Import ZIP' }}
                    </Button>
                </form>
            </div>
        </section>

        <section class="bg-card rounded-xl border">
            <div class="flex flex-col gap-2 border-b p-5 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-sm font-semibold">Remote environments</h2>
                    <p class="text-muted-foreground mt-1 text-xs">
                        Pull published production or staging values for review. Choose “Pull including drafts” to
                        include unpublished wording. Last successful sync:
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
                <p class="text-muted-foreground mt-1 text-xs">
                    Add a production or staging environment below to pull its translations for review.
                </p>
            </div>

            <div
                v-for="environment in environments"
                v-else
                :key="environment.id"
                class="flex flex-col gap-4 border-b p-4 sm:flex-row sm:items-center"
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
                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    <Button
                        :disabled="pullingId !== null || isSyncingLocal"
                        size="sm"
                        @click="pull(environment)"
                    >
                        <RefreshCw :class="['size-4', pullingId === environment.id && 'animate-spin']" />
                        {{ pullingId === environment.id ? 'Pulling…' : 'Pull published' }}
                    </Button>
                    <Button
                        :disabled="pullingId !== null || isSyncingLocal"
                        size="sm"
                        variant="ghost"
                        @click="pull(environment, true)"
                    >
                        Pull including drafts
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

            <div :class="['p-5', environments.length === 0 && 'border-t']">
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
                            placeholder="Production or staging"
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
                            {{
                                form.processing
                                    ? 'Saving…'
                                    : editingId === null
                                      ? 'Add environment'
                                      : 'Save environment'
                            }}
                        </Button>
                    </div>
                </form>
            </div>
        </section>

        <RemoteReconciliationPanel
            :review="page.props.reconciliation"
            :can-choose-with-ai="page.props.ai.can_choose"
            :environments="environments"
        />
    </div>
    <SlidePanel
        :open="removingLocale !== null"
        :title="`Remove ${removingLocale?.name ?? 'language'}`"
        @close="!removalForm.processing && (removingLocale = null)"
    >
        <div class="space-y-4 text-sm">
            <p>
                Permanently remove {{ removingLocale?.name }} ({{ removingLocale?.code }}), including its translations,
                unpublished edits, fallback rules, sync review data, and generated language files.
            </p>
            <p class="font-semibold">This takes effect immediately. You do not need to Publish.</p>
            <p>Other languages and shared translation keys will be kept.</p>
            <p
                v-for="(error, field) in removalForm.errors"
                :key="field"
                role="alert"
                class="text-destructive"
            >
                {{ error }}
            </p>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <Button
                    variant="outline"
                    :disabled="removalForm.processing"
                    @click="removingLocale = null"
                    >Cancel</Button
                >
                <Button
                    variant="destructive"
                    data-test="confirm-remove-locale"
                    :disabled="removalForm.processing"
                    @click="removeLocale"
                    >{{ removalForm.processing ? 'Removing…' : 'Remove language' }}</Button
                >
            </div>
        </template>
    </SlidePanel>
</template>
