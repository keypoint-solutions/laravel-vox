import type { ComputedRef, DeepReadonly, Plugin, Ref } from 'vue';

export { trans, trans_choice, transChoice, wTrans, wTransChoice } from 'laravel-vue-i18n';

export interface VoxI18nOptions {
    locale?: string | readonly string[];
    fallbackLocale?: string;
    persist?: 'local' | 'session' | false;
    storageKey?: string;
    onLoad?: (locale: string) => void;
}

export const availableVoxLocales: string[];
export function createVoxI18n(options?: VoxI18nOptions): Plugin;
export function createVox(options?: VoxI18nOptions): Promise<Plugin>;
export interface VoxComposable {
    locale: Readonly<Ref<string>>;
    locales: ComputedRef<DeepReadonly<string[]>>;
    setLocale(locale: string | readonly string[]): Promise<void>;
}
export function useVox(): VoxComposable;
export function setVoxLocale(locale: string | readonly string[]): Promise<void>;
export default createVoxI18n;
