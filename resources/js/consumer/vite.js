import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

import { normalizePath } from 'vite';

import { PhpTranslationCatalogue } from './php-catalogue.js';

const virtualModuleId = 'virtual:laravel-vox/translations';
const resolvedVirtualModuleId = `\0${virtualModuleId}`;
const phpModulePrefix = 'virtual:laravel-vox/php/';
const resolvedPhpModulePrefix = `\0${phpModulePrefix}`;

function resolveFrontendGroups(root, options) {
    if (Array.isArray(options.frontendGroups)) return options.frontendGroups;
    const manifestPath = resolve(root, options.manifestPath ?? 'storage/vox/frontend.json');
    if (!existsSync(manifestPath)) return [];
    try {
        const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
        return Array.isArray(manifest.groups)
            ? manifest.groups.filter((group) => typeof group === 'string' && group !== '')
            : [];
    } catch {
        return [];
    }
}

/**
 * Compile PHP translations once, then reparse only edited files during development.
 * @param {import('./vite.js').LaravelVoxViteOptions} options
 * @returns {import('vite').PluginOption[]}
 */
export default function laravelVox(options = {}) {
    let root = process.cwd();
    let langPath;
    let manifestPath;
    let catalogue;
    let frontendGroups = [];
    const jsonContents = new Map();
    const outputs = new Map();
    const events = new Map();

    function jsonFiles() {
        return existsSync(langPath)
            ? readdirSync(langPath)
                  .filter((file) => file.endsWith('.json') && !file.startsWith('php_'))
                  .sort()
            : [];
    }

    function virtualSource() {
        const json = jsonFiles();
        const locales = [...new Set([...catalogue.locales(), ...json.map((file) => file.slice(0, -5))])].sort();
        const loaders = [
            ...json.map(
                (file) =>
                    `${JSON.stringify(file.slice(0, -5))}: () => import(${JSON.stringify(`/@fs/${normalizePath(resolve(langPath, file))}`)})`
            ),
            ...catalogue
                .locales()
                .map(
                    (locale) =>
                        `${JSON.stringify(`php_${locale}`)}: () => import(${JSON.stringify(phpModulePrefix + encodeURIComponent(locale))})`
                ),
        ];
        return `const loaders = {${loaders.join(',\n')}};
export const availableVoxLocales = ${JSON.stringify(locales)};
export async function loadVoxLocale(locale) { return loaders[locale] ? await loaders[locale]() : {}; }
`;
    }

    function updateOutputs(locales) {
        const changed = [];
        for (const locale of locales) {
            const id = resolvedPhpModulePrefix + encodeURIComponent(locale);
            const source = `export default ${JSON.stringify(catalogue.messages(locale, frontendGroups))};`;
            if (outputs.get(id) !== source) {
                outputs.set(id, source);
                changed.push(id);
            }
        }
        const source = virtualSource();
        if (outputs.get(resolvedVirtualModuleId) !== source) {
            outputs.set(resolvedVirtualModuleId, source);
            changed.push(resolvedVirtualModuleId);
        }
        return changed;
    }

    const aliases = {
        name: 'laravel-vox-alias',
        config() {
            return {
                resolve: {
                    alias: {
                        ...(options.runtime
                            ? { '@laravel-vox/vue.js': fileURLToPath(new URL('./runtime.js', import.meta.url)) }
                            : {}),
                        '@laravel-vox': fileURLToPath(new URL('.', import.meta.url)),
                    },
                    dedupe: ['vue', 'laravel-vue-i18n'],
                },
            };
        },
    };
    if (options.runtime) return [aliases];

    return [
        aliases,
        {
            name: 'laravel-vox-translations',
            configResolved(config) {
                root = config.root;
                langPath = resolve(root, options.langPath ?? 'lang');
                manifestPath = resolve(root, options.manifestPath ?? 'storage/vox/frontend.json');
                catalogue = new PhpTranslationCatalogue([
                    resolve(root, 'vendor/laravel/framework/src/Illuminate/Translation/lang'),
                    langPath,
                    ...(options.additionalLangPaths ?? []).map((path) => resolve(root, path)),
                ]);
            },
            buildStart() {
                frontendGroups = resolveFrontendGroups(root, options);
                catalogue.initialize();
                outputs.clear();
                jsonContents.clear();
                for (const file of jsonFiles())
                    jsonContents.set(resolve(langPath, file), readFileSync(resolve(langPath, file), 'utf8'));
                updateOutputs(catalogue.locales());
            },
            resolveId(id) {
                if (id === virtualModuleId || id.startsWith(phpModulePrefix)) return `\0${id}`;
            },
            load(id) {
                if (outputs.has(id)) return outputs.get(id);
            },
            configureServer(server) {
                server.watcher.add([...catalogue.roots, manifestPath]);
            },
            hotUpdate: {
                order: 'pre',
                async handler(context) {
                    const file = resolve(context.file);
                    const isPhp = catalogue.describe(file) !== null;
                    const isJson =
                        normalizePath(file).startsWith(`${normalizePath(langPath)}/`) &&
                        file.slice(langPath.length + 1).match(/^(?!php_)[^/\\]+\.json$/u);
                    const isManifest = file === manifestPath && !Array.isArray(options.frontendGroups);
                    if (!isPhp && !isJson && !isManifest) return;

                    // Vite invokes this hook once per environment for the same filesystem event.
                    const eventKey = `${context.type}:${context.timestamp}:${file}`;
                    if (!events.has(eventKey)) {
                        const update = async () => {
                            if (isManifest) {
                                frontendGroups = resolveFrontendGroups(root, options);
                                return updateOutputs(catalogue.locales());
                            }
                            const contents = context.type === 'delete' ? null : await context.read();
                            if (isPhp) {
                                const locale = catalogue.update(file, contents);
                                return locale === null ? [] : updateOutputs([locale]);
                            }
                            if (jsonContents.get(file) === contents || (contents === null && !jsonContents.has(file)))
                                return [];
                            if (contents === null) jsonContents.delete(file);
                            else jsonContents.set(file, contents);
                            return [file, ...updateOutputs([])];
                        };
                        events.set(eventKey, update());
                        if (events.size > 100) events.delete(events.keys().next().value);
                    }
                    const changed = await events.get(eventKey);
                    const graph = this.environment.moduleGraph;
                    const modules = new Set();
                    for (const id of changed) {
                        if (id === file) {
                            for (const module of context.modules) modules.add(module);
                        } else {
                            const module = graph.getModuleById(id);
                            if (module) modules.add(module);
                        }
                    }
                    return [...modules];
                },
            },
        },
    ];
}
