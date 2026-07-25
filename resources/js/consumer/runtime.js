import { i18nVue, loadLanguageAsync } from 'laravel-vue-i18n';

export { trans, trans_choice, transChoice, wTrans, wTransChoice } from 'laravel-vue-i18n';

export const availableVoxLocales = [];

let runtimeEndpoint = '/vox/translations/{locale}';
let runtimeFetcher = globalThis.fetch.bind(globalThis);

function normalizeLocale(locale) {
    return locale.trim().replace('-', '_');
}

function configureLocales(locales) {
    const normalizedLocales = [...new Set(locales.map(normalizeLocale).filter(Boolean))];

    availableVoxLocales.splice(0, availableVoxLocales.length, ...normalizedLocales);
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

function endpointForLocale(locale) {
    if (typeof runtimeEndpoint === 'function') {
        return runtimeEndpoint(locale);
    }

    if (runtimeEndpoint.includes('{locale}')) {
        return runtimeEndpoint.replace('{locale}', encodeURIComponent(locale));
    }

    return `${runtimeEndpoint.replace(/\/$/u, '')}/${encodeURIComponent(locale)}`;
}

async function loadVoxLocale(locale) {
    if (locale.startsWith('php_')) {
        return { default: {} };
    }

    const response = await runtimeFetcher(endpointForLocale(locale), {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
        },
    });

    if (!response.ok) {
        throw new Error(`Unable to load Laravel Vox translations for [${locale}] (${response.status}).`);
    }

    return { default: await response.json() };
}

/**
 * Create the Vue plugin that loads prebuilt Laravel translations from the
 * Laravel Vox runtime endpoint through laravel-vue-i18n.
 *
 * @param {{
 *   locale?: string,
 *   fallbackLocale?: string,
 *   locales?: string[],
 *   endpoint?: string | ((locale: string) => string),
 *   fetcher?: typeof fetch,
 *   onLoad?: (locale: string) => void
 * }} options
 * @returns {import('vue').Plugin}
 */
export function createVoxI18n(options = {}) {
    const fallbackLocale = normalizeLocale(options.fallbackLocale ?? 'en');
    const configuredLocales =
        options.locales ?? [options.locale, document.documentElement.lang, fallbackLocale].filter(Boolean);

    configureLocales(configuredLocales);
    runtimeEndpoint = options.endpoint ?? '/vox/translations/{locale}';
    runtimeFetcher = options.fetcher ?? globalThis.fetch.bind(globalThis);

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

export default createVoxI18n;
