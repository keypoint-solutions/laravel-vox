export interface AppPageProps {
    vox?: {
        features?: Record<string, boolean>;
        routes?: {
            dashboard: string;
            sync: string;
            sync_remote: string;
            sync_local: string;
            sync_environment_store: string;
            sync_environment_update: string;
            sync_environment_destroy: string;
            sync_environment_pull: string;
            manage: string;
            manage_translation_update: string;
            manage_translation_toggle_approval: string;
            manage_translation_bulk_approval: string;
            manage_translation_translate: string;
            publish: string;
            publish_store: string;
            audit: string;
            settings: string;
            settings_update: string;
            settings_ai_models_refresh: string;
        };
        sync_enabled?: boolean;
    };
}

export interface SelectOption {
    value: string;
    label: string;
}

export interface SettingsData {
    translate_guidance: string;
    sync_enabled: boolean;
    sync_key_set: boolean;
}
