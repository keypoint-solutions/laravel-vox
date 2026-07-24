import { i18nVue, loadLanguageAsync } from 'laravel-vue-i18n';
import { availableVoxLocales, loadVoxLocale } from 'virtual:laravel-vox/translations';

export { trans, trans_choice, transChoice, wTrans, wTransChoice } from 'laravel-vue-i18n';

function normalizeLocale(locale) {
    return locale.trim().replace('-', '_');
}

function resolveLocale(requestedLocale, fallbackLocale) {
    const candidates = [requestedLocale, document.documentElement.lang, fallbackLocale, availableVoxLocales[0]]
        .filter((locale) => typeof locale === 'string' && locale !== '')
        .flatMap((locale) => {
            const normalized = normalizeLocale(locale);
            const base = normalized.split('_')[0];

            return normalized === base ? [normalized] : [normalized, base];
        });

    return candidates.find((locale) => availableVoxLocales.includes(locale)) ?? fallbackLocale;
}

/**
 * Create the Vue plugin that loads a consuming Laravel application's
 * translations through laravel-vue-i18n.
 *
 * @param {{
 *   locale?: string,
 *   fallbackLocale?: string,
 *   onLoad?: (locale: string) => void
 * }} options
 * @returns {import('vue').Plugin}
 */
export function createVoxI18n(options = {}) {
    const fallbackLocale = normalizeLocale(options.fallbackLocale ?? 'en');
    const locale = resolveLocale(options.locale, fallbackLocale);

    return {
        install(app) {
            app.use(i18nVue, {
                lang: locale,
                resolve: loadVoxLocale,
                onLoad: (loadedLocale) => {
                    document.documentElement.lang = loadedLocale.replace('_', '-');
                    options.onLoad?.(loadedLocale);
                },
            });
        },
    };
}

export async function setVoxLocale(locale) {
    const normalizedLocale = resolveLocale(locale, normalizeLocale(locale));

    await loadLanguageAsync(normalizedLocale);
    document.documentElement.lang = normalizedLocale.replace('_', '-');
}

export { availableVoxLocales };

export default createVoxI18n;
