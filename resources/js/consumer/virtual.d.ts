declare module 'virtual:laravel-vox/translations' {
    export const availableVoxLocales: string[];
    export function loadVoxLocale(locale: string): Promise<Record<string, unknown>>;
}
