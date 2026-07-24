<script lang="ts" setup>
    import { Head, router, useForm, usePage } from '@inertiajs/vue3';
    import { Check, KeyRound, Loader2, RefreshCw, ShieldCheck } from '@lucide/vue';
    import { computed, ref, watch } from 'vue';

    import { Badge, Button, Checkbox, FormField, Select, Textarea } from '@/components/ui';
    import { useDateTime } from '@/composables/useDateTime';
    import Layout from '@/layouts/Layout.vue';

    defineOptions({
        layout: Layout,
    });

    interface ModelOption {
        value: string;
        label: string;
        available: boolean;
    }

    interface SettingsProps {
        settings: {
            translate_guidance: string;
            sync_enabled: boolean;
            sync_key_set: boolean;
        };
        ai: {
            provider: {
                id: string;
                label: string;
                credentials_set: boolean;
            };
            model: string;
            status: 'connected' | 'missing_key' | 'error' | 'unavailable';
            message: string;
            checked_at: string | null;
            models: ModelOption[];
        };
    }

    const page = usePage<SettingsProps>();
    const { formatDateTime } = useDateTime();
    const settings = computed(() => page.props.settings);
    const ai = computed(() => page.props.ai);
    const settingsUpdateRoute = computed(() => page.props.vox?.routes?.settings_update ?? page.url.split('?')[0]);
    const modelsRefreshRoute = computed(
        () => page.props.vox?.routes?.settings_ai_models_refresh ?? `${page.url.split('?')[0]}/ai/models`
    );

    const aiForm = useForm({
        section: 'ai',
        model: ai.value.model,
        translate_guidance: settings.value.translate_guidance,
    });
    const syncForm = useForm({
        section: 'sync',
        sync_enabled: settings.value.sync_enabled,
    });

    const aiSaved = ref(false);
    const syncSaved = ref(false);
    const refreshingModels = ref(false);
    const modelRefreshSuccess = ref(false);
    const modelRefreshError = ref('');

    const connectionBadgeVariant = computed(() => {
        if (ai.value.status === 'connected') {
            return 'success';
        }

        if (ai.value.status === 'error' || ai.value.status === 'unavailable') {
            return 'destructive';
        }

        return 'warning';
    });

    const connectionLabel = computed(() => {
        if (ai.value.status === 'connected') {
            return 'Connected';
        }

        if (ai.value.status === 'error') {
            return 'Connection failed';
        }

        if (ai.value.status === 'unavailable') {
            return 'Discovery unavailable';
        }

        return 'Credentials missing';
    });

    watch(
        () => [aiForm.model, aiForm.translate_guidance],
        () => {
            if (aiForm.isDirty) {
                aiSaved.value = false;
            }
        }
    );

    watch(
        () => syncForm.sync_enabled,
        () => {
            if (syncForm.isDirty) {
                syncSaved.value = false;
            }
        }
    );

    function saveAiSettings(): void {
        aiSaved.value = false;

        aiForm.post(settingsUpdateRoute.value, {
            preserveScroll: true,
            onSuccess: () => {
                aiForm.defaults();
                aiSaved.value = true;
            },
        });
    }

    function saveSyncSettings(): void {
        syncSaved.value = false;

        syncForm.post(settingsUpdateRoute.value, {
            preserveScroll: true,
            onSuccess: () => {
                syncForm.defaults();
                syncSaved.value = true;
            },
        });
    }

    function refreshModels(): void {
        modelRefreshError.value = '';
        modelRefreshSuccess.value = false;

        router.post(
            modelsRefreshRoute.value,
            {},
            {
                preserveScroll: true,
                onStart: () => {
                    refreshingModels.value = true;
                },
                onError: (errors) => {
                    modelRefreshError.value = errors.models ?? 'Model discovery failed.';
                },
                onSuccess: () => {
                    modelRefreshSuccess.value = true;
                },
                onFinish: () => {
                    refreshingModels.value = false;
                },
            }
        );
    }
</script>

<template>
    <div class="space-y-8">
        <Head title="Settings" />

        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Settings</h1>
            <p class="text-muted-foreground mt-1 text-sm">
                Manage runtime translation preferences. Credentials remain owned by the application environment.
            </p>
        </div>

        <section class="bg-card overflow-hidden rounded-xl border">
            <div class="flex flex-col gap-4 border-b p-6 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-lg font-semibold">AI translation</h2>
                        <Badge :variant="connectionBadgeVariant">{{ connectionLabel }}</Badge>
                    </div>
                    <p class="text-muted-foreground mt-1 max-w-2xl text-sm">
                        The active provider and its credentials are deployment settings. Runtime model and translation
                        guidance can be managed here.
                    </p>
                </div>
                <Button
                    :disabled="!ai.provider.credentials_set || refreshingModels"
                    size="sm"
                    type="button"
                    variant="outline"
                    @click="refreshModels"
                >
                    <Loader2
                        v-if="refreshingModels"
                        class="size-4 animate-spin"
                    />
                    <RefreshCw
                        v-else
                        class="size-4"
                    />
                    Refresh & test
                </Button>
            </div>

            <div class="grid gap-6 p-6 lg:grid-cols-[minmax(0,1fr)_260px]">
                <form
                    class="space-y-6"
                    @submit.prevent="saveAiSettings"
                >
                    <div
                        v-if="aiForm.errors.general"
                        role="alert"
                        class="text-destructive border-destructive/40 bg-destructive/10 rounded-lg border p-3 text-sm"
                    >
                        {{ aiForm.errors.general }}
                    </div>

                    <FormField
                        id="model"
                        :error="aiForm.errors.model"
                        description="Only supported text models available to the active provider account are listed."
                        label="Translation model"
                    >
                        <Select
                            id="model"
                            v-model="aiForm.model"
                            :disabled="ai.models.length === 0"
                            :options="ai.models"
                            placeholder="No supported models found"
                        />
                    </FormField>

                    <FormField
                        id="translate_guidance"
                        :error="aiForm.errors.translate_guidance"
                        description="Optional project terminology or tone guidance. Placeholder and output-safety rules remain enforced by Laravel Vox."
                        label="Additional translation guidance"
                    >
                        <Textarea
                            id="translate_guidance"
                            v-model="aiForm.translate_guidance"
                            :rows="5"
                            placeholder="For example: Use formal French and keep product names in English."
                        />
                    </FormField>

                    <div class="flex flex-wrap items-center gap-3 border-t pt-5">
                        <Button
                            :disabled="aiForm.processing || !aiForm.isDirty"
                            type="submit"
                        >
                            <Loader2
                                v-if="aiForm.processing"
                                class="size-4 animate-spin"
                            />
                            <span>{{ aiForm.processing ? 'Saving…' : 'Save AI settings' }}</span>
                        </Button>
                        <span
                            v-if="aiSaved"
                            role="status"
                            aria-live="polite"
                            class="flex items-center gap-1.5 text-sm text-emerald-600"
                        >
                            <Check class="size-4" />
                            AI settings saved
                        </span>
                    </div>
                </form>

                <aside class="bg-muted/30 space-y-4 rounded-lg border p-4">
                    <div class="flex items-start gap-3">
                        <ShieldCheck class="mt-0.5 size-4 shrink-0 text-emerald-500" />
                        <div>
                            <p class="text-sm font-medium">Active provider</p>
                            <p class="text-muted-foreground mt-0.5 text-xs">{{ ai.provider.label }}</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <KeyRound class="mt-0.5 size-4 shrink-0 text-violet-500" />
                        <div>
                            <p class="text-sm font-medium">
                                {{
                                    ai.provider.credentials_set
                                        ? 'Credentials configured'
                                        : 'Credentials not configured'
                                }}
                            </p>
                            <p class="text-muted-foreground mt-0.5 text-xs">
                                Managed through the application environment.
                            </p>
                        </div>
                    </div>
                    <div class="border-t pt-4">
                        <p class="text-muted-foreground text-xs">{{ ai.message }}</p>
                        <p class="text-muted-foreground mt-2 text-xs">
                            Last checked: {{ formatDateTime(ai.checked_at, 'Not checked yet') }}
                        </p>
                    </div>
                    <p
                        v-if="modelRefreshError"
                        role="alert"
                        class="text-destructive text-xs"
                    >
                        {{ modelRefreshError }}
                    </p>
                    <p
                        v-if="modelRefreshSuccess"
                        role="status"
                        aria-live="polite"
                        class="flex items-center gap-1.5 text-xs text-emerald-600"
                    >
                        <Check class="size-3.5" />
                        Connection verified
                    </p>
                </aside>
            </div>
        </section>

        <section class="bg-card rounded-xl border">
            <div class="border-b p-6">
                <h2 class="text-lg font-semibold">Remote sync</h2>
                <p class="text-muted-foreground mt-1 text-sm">
                    Control whether this environment accepts authenticated translation archive requests.
                </p>
            </div>

            <form
                class="space-y-6 p-6"
                @submit.prevent="saveSyncSettings"
            >
                <div
                    v-if="syncForm.errors.general"
                    role="alert"
                    class="text-destructive border-destructive/40 bg-destructive/10 rounded-lg border p-3 text-sm"
                >
                    {{ syncForm.errors.general }}
                </div>

                <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
                    <FormField
                        id="sync_enabled"
                        description="Disable this when the environment should not serve translation archives."
                        label="Remote sync enabled"
                    >
                        <Checkbox
                            id="sync_enabled"
                            v-model="syncForm.sync_enabled"
                        />
                    </FormField>

                    <div class="bg-muted/30 min-w-56 rounded-lg border p-4">
                        <div class="flex items-center justify-between gap-4">
                            <span class="text-sm font-medium">Sync key</span>
                            <Badge :variant="settings.sync_key_set ? 'success' : 'warning'">
                                {{ settings.sync_key_set ? 'Configured' : 'Missing' }}
                            </Badge>
                        </div>
                        <p class="text-muted-foreground mt-2 text-xs">
                            The secret is never rendered in the browser. Generate it with
                            <code>php artisan vox:generate-sync-key</code>.
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-3 border-t pt-5">
                    <Button
                        :disabled="syncForm.processing || !syncForm.isDirty"
                        type="submit"
                    >
                        <Loader2
                            v-if="syncForm.processing"
                            class="size-4 animate-spin"
                        />
                        <span>{{ syncForm.processing ? 'Saving…' : 'Save sync setting' }}</span>
                    </Button>
                    <span
                        v-if="syncSaved"
                        role="status"
                        aria-live="polite"
                        class="flex items-center gap-1.5 text-sm text-emerald-600"
                    >
                        <Check class="size-4" />
                        Sync setting saved
                    </span>
                </div>
            </form>
        </section>
    </div>
</template>
