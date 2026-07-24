import { existsSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import process from 'node:process';

import laravelVueI18n from 'laravel-vue-i18n/vite';
import { normalizePath } from 'vite';

const virtualModuleId = 'virtual:laravel-vox/translations';
const resolvedVirtualModuleId = `\0${virtualModuleId}`;

function localeFromFilename(filename) {
    const basename = filename.replace(/\.json$/u, '');

    return basename.startsWith('php_') ? basename.slice(4) : basename;
}

function buildVirtualModule(langPath) {
    if (!existsSync(langPath)) {
        return 'export const availableVoxLocales = []; export async function loadVoxLocale() { return {}; }';
    }

    const files = readdirSync(langPath)
        .filter((filename) => filename.endsWith('.json'))
        .sort();
    const loaderSource = files
        .map((filename) => {
            const name = filename.replace(/\.json$/u, '');
            const file = `/@fs/${normalizePath(resolve(langPath, filename))}`;

            return `${JSON.stringify(name)}: () => import(${JSON.stringify(file)})`;
        })
        .join(',\n');
    const locales = [...new Set(files.map(localeFromFilename))];

    return `
const loaders = {
${loaderSource}
};

export const availableVoxLocales = ${JSON.stringify(locales)};

export async function loadVoxLocale(locale) {
    const load = loaders[locale];

    if (!load) {
        return {};
    }

    return await load();
}
`;
}

function resolveFrontendGroups(root, options) {
    if (Array.isArray(options.frontendGroups)) {
        return options.frontendGroups;
    }

    const manifestPath = resolve(root, options.manifestPath ?? 'storage/vox/frontend.json');

    if (!existsSync(manifestPath)) {
        return [];
    }

    try {
        const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));

        return Array.isArray(manifest.groups)
            ? manifest.groups.filter((group) => typeof group === 'string' && group !== '')
            : [];
    } catch {
        return [];
    }
}

function filterPhpTranslations(langPath, groups) {
    if (!existsSync(langPath) || groups.includes('*')) {
        return;
    }

    for (const filename of readdirSync(langPath).filter((file) => /^php_.+\.json$/u.test(file))) {
        const path = resolve(langPath, filename);
        const translations = JSON.parse(readFileSync(path, 'utf8'));
        const filtered = {};

        for (const [key, value] of Object.entries(translations)) {
            if (groups.some((group) => key === group || key.startsWith(`${group}.`))) {
                filtered[key] = value;
            }
        }

        writeFileSync(path, JSON.stringify(filtered));
    }
}

/**
 * Make a Laravel application's PHP and JSON translations available to the
 * Laravel Vox Vue integration.
 *
 * @param {{ langPath?: string, frontendGroups?: string[], manifestPath?: string }} options
 * @returns {import('vite').PluginOption[]}
 */
export default function laravelVox(options = {}) {
    let root = process.cwd();
    let langPath = resolve(root, options.langPath ?? 'lang');
    let frontendGroups = [];

    return [
        laravelVueI18n(options.langPath),
        {
            name: 'laravel-vox-translations',
            enforce: 'post',
            configResolved(config) {
                root = config.root;
                langPath = resolve(root, options.langPath ?? 'lang');
                frontendGroups = resolveFrontendGroups(root, options);
            },
            buildStart() {
                filterPhpTranslations(langPath, frontendGroups);
            },
            resolveId(id) {
                if (id === virtualModuleId) {
                    return resolvedVirtualModuleId;
                }
            },
            load(id) {
                if (id === resolvedVirtualModuleId) {
                    return buildVirtualModule(langPath);
                }
            },
            configureServer(server) {
                server.watcher.add(langPath);
                server.watcher.on('all', (_event, path) => {
                    if (!normalizePath(path).startsWith(`${normalizePath(langPath)}/`)) {
                        return;
                    }

                    const module = server.moduleGraph.getModuleById(resolvedVirtualModuleId);

                    if (module) {
                        server.moduleGraph.invalidateModule(module);
                    }
                });
            },
            handleHotUpdate(context) {
                if (normalizePath(context.file).startsWith(`${normalizePath(langPath)}/`)) {
                    filterPhpTranslations(langPath, frontendGroups);
                }
            },
        },
    ];
}
