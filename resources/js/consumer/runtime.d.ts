import type { Plugin } from 'vue';

export { trans, trans_choice, transChoice, wTrans, wTransChoice } from 'laravel-vue-i18n';

export interface VoxRuntimeI18nOptions {
    locale?: string;
    fallbackLocale?: string;
    locales?: string[];
    endpoint?: string | ((locale: string) => string);
    fetcher?: typeof fetch;
    onLoad?: (locale: string) => void;
}

export const availableVoxLocales: string[];
export function createVoxI18n(options?: VoxRuntimeI18nOptions): Plugin;
export function setVoxLocale(locale: string): Promise<void>;
export default createVoxI18n;
