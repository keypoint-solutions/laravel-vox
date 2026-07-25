import type { Plugin } from 'vue';

export { trans, trans_choice, transChoice, wTrans, wTransChoice } from 'laravel-vue-i18n';

export interface VoxRuntimeI18nOptions {
    locale?: string;
    fallbackLocale?: string;
    locales?: string[];
    endpoint?: string | ((locale: string) => string);
    localesEndpoint?: string;
    fetcher?: typeof fetch;
    onLoad?: (locale: string) => void;
    onLocalesLoad?: (catalog: VoxLocaleCatalog) => void;
    onLocalesError?: (error: Error) => void;
}

export interface VoxLocaleDefinition {
    code: string;
    name: string;
    is_default: boolean;
    has_runtime_translations: boolean;
}

export interface VoxLocaleCatalog {
    default_locale: string;
    locales: VoxLocaleDefinition[];
}

export const availableVoxLocales: string[];
export function fetchVoxLocales(options?: { endpoint?: string; fetcher?: typeof fetch }): Promise<VoxLocaleCatalog>;
export function createVoxI18n(options?: VoxRuntimeI18nOptions): Plugin;
export function setVoxLocale(locale: string): Promise<void>;
export default createVoxI18n;
