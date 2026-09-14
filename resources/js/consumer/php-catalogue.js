import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { isAbsolute, relative, resolve, sep } from 'node:path';

import { parse } from 'laravel-vue-i18n/loader';

/** Parsed source files are shared by the Vite environments, independently of their module graphs. */
export class PhpTranslationCatalogue {
    constructor(roots, parseFile = parse) {
        this.roots = [...new Set(roots.map((root) => resolve(root)))];
        this.parseFile = parseFile;
        this.files = new Map();
    }

    describe(file) {
        file = resolve(file);
        for (let priority = this.roots.length - 1; priority >= 0; priority--) {
            const path = relative(this.roots[priority], file);
            if (isAbsolute(path) || path.startsWith(`..${sep}`) || path === '..' || !path.endsWith('.php')) continue;
            const parts = path.split(sep);
            const vendor = parts[0] === 'vendor';
            const namespace = vendor ? parts.splice(0, 2)[1] : null;
            const locale = parts.shift();
            if (!locale || parts.length === 0) continue;
            const group = parts.join('.').slice(0, -4);
            return { file, priority, vendor, locale, group: namespace ? `${namespace}::${group}` : group };
        }
        return null;
    }

    initialize() {
        this.files.clear();
        const visit = (directory) => {
            if (!existsSync(directory)) return;
            for (const entry of readdirSync(directory, { withFileTypes: true }).sort((a, b) =>
                a.name.localeCompare(b.name)
            )) {
                const file = resolve(directory, entry.name);
                if (entry.isDirectory()) visit(file);
                else if (entry.isFile() && file.endsWith('.php')) this.update(file);
            }
        };
        for (const root of this.roots) visit(root);
    }

    update(file, contents = existsSync(file) ? readFileSync(file, 'utf8') : null) {
        const description = this.describe(file);
        if (!description) return null;
        const previous = this.files.get(description.file);
        if (contents === null) {
            return this.files.delete(description.file) ? description.locale : null;
        }
        if (previous?.contents === contents) return null;
        // Parse before replacing cached contents, so invalid edits cannot poison the cache.
        const translations = this.parseFile(contents);
        this.files.set(description.file, { ...description, contents, translations });
        return description.locale;
    }

    locales() {
        return [...new Set([...this.files.values()].map((file) => file.locale))].sort();
    }

    messages(locale, groups) {
        const result = {};
        const files = [...this.files.values()]
            .filter((file) => file.locale === locale)
            .sort(
                (a, b) => a.priority - b.priority || Number(a.vendor) - Number(b.vendor) || a.file.localeCompare(b.file)
            );
        for (const file of files) {
            for (const [key, value] of Object.entries(file.translations)) {
                const fullKey = `${file.group}.${key}`;
                if (
                    groups.includes('*') ||
                    groups.some((group) => fullKey === group || fullKey.startsWith(`${group}.`))
                ) {
                    result[fullKey] = value;
                }
            }
        }
        return result;
    }
}
