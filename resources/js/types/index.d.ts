export interface AppPageProps {
    vox?: {
        features?: Record<string, boolean>;
        routes?: {
            dashboard: string;
            sync: string;
            sync_remote: string;
            manage: string;
            manage_translation_update: string;
            manage_translation_toggle_approval: string;
            manage_translation_translate: string;
            publish: string;
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
