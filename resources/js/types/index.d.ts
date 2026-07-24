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
        };
        sync_enabled?: boolean;
    };
}

export interface SelectOption {
    value: string;
    label: string;
}

export interface SettingsData {
    translate_driver: string;
    translate_prompt: string;
    terms_do_not_translate: string;
    terms_fixed: string;
    sync_key: string;
    openai_api_key: string;
    openai_api_key_set: boolean;
    openai_model: string;
    openai_endpoint: string;
    openai_temperature: number;
}
