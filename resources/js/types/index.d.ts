export interface AppPageProps {
    vox?: {
        features?: Record<string, boolean>;
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
