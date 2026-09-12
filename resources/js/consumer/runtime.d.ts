import type { Plugin } from 'vue';

import type { VoxComposable } from './vue.js';

export { trans, trans_choice, transChoice, wTrans, wTransChoice } from 'laravel-vue-i18n';

export interface VoxRuntimeI18nOptions {
    locale?: string | readonly string[];
    fallbackLocale?: string;
    persist?: 'local' | 'session' | false;
    storageKey?: string;
    locales?: string[];
    baseUrl?: string;
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
export function fetchVoxLocales(options?: {
    baseUrl?: string;
    endpoint?: string;
    fetcher?: typeof fetch;
}): Promise<VoxLocaleCatalog>;
export function createVoxI18n(options?: VoxRuntimeI18nOptions): Plugin;
export function createVox(options?: VoxRuntimeI18nOptions): Promise<Plugin>;
export function useVox(): VoxComposable;
export function setVoxLocale(locale: string | readonly string[]): Promise<void>;
export default createVoxI18n;
