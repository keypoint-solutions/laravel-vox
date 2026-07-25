import { i18nVue, loadLanguageAsync } from 'laravel-vue-i18n';

export { trans, trans_choice, transChoice, wTrans, wTransChoice } from 'laravel-vue-i18n';

export const availableVoxLocales = [];

let runtimeEndpoint = '/vox/translations/{locale}';
let runtimeLocalesEndpoint = '/vox/locales';
let runtimeFetcher = globalThis.fetch.bind(globalThis);

function normalizeLocale(locale) {
    return locale.trim().replaceAll('-', '_');
}

function configureLocales(locales) {
    const normalizedLocales = [...new Set(locales.map(normalizeLocale).filter(Boolean))];

    availableVoxLocales.splice(0, availableVoxLocales.length, ...normalizedLocales);
}

/**
 * Fetch the application's locale catalogue from Laravel Vox.
 *
 * @param {{ endpoint?: string, fetcher?: typeof fetch }} options
 * @returns {Promise<{
 *   default_locale: string,
 *   locales: Array<{
 *     code: string,
 *     name: string,
 *     is_default: boolean,
 *     has_runtime_translations: boolean
 *   }>
 * }>}
 */
export async function fetchVoxLocales(options = {}) {
    const fetcher = options.fetcher ?? runtimeFetcher;
    const response = await fetcher(options.endpoint ?? runtimeLocalesEndpoint, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
        },
    });

    if (!response.ok) {
        throw new Error(`Unable to load the Laravel Vox locale catalogue (${response.status}).`);
    }

    const catalog = await response.json();
    const locales = Array.isArray(catalog.locales)
        ? catalog.locales
              .filter((locale) => locale && typeof locale.code === 'string')
              .map((locale) => ({ ...locale, code: normalizeLocale(locale.code) }))
        : [];
    const defaultLocale = normalizeLocale(
        typeof catalog.default_locale === 'string' ? catalog.default_locale : (locales[0]?.code ?? 'en')
    );

    configureLocales(locales.map((locale) => locale.code));

    return {
        ...catalog,
        default_locale: defaultLocale,
        locales,
    };
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
 *   localesEndpoint?: string,
 *   fetcher?: typeof fetch,
 *   onLoad?: (locale: string) => void,
 *   onLocalesLoad?: (catalog: object) => void,
 *   onLocalesError?: (error: Error) => void
 * }} options
 * @returns {import('vue').Plugin}
 */
export function createVoxI18n(options = {}) {
    const fallbackLocale = normalizeLocale(options.fallbackLocale ?? 'en');
    const configuredLocales =
        options.locales ?? [options.locale, document.documentElement.lang, fallbackLocale].filter(Boolean);

    configureLocales(configuredLocales);
    runtimeEndpoint = options.endpoint ?? '/vox/translations/{locale}';
    runtimeLocalesEndpoint = options.localesEndpoint ?? '/vox/locales';
    runtimeFetcher = options.fetcher ?? globalThis.fetch.bind(globalThis);

    if (options.locales === undefined) {
        void fetchVoxLocales()
            .then((catalog) => options.onLocalesLoad?.(catalog))
            .catch((error) => options.onLocalesError?.(error));
    }

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
