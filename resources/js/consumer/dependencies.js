import { existsSync, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import process from 'node:process';

const dependency = 'laravel-vue-i18n';
const require = createRequire(import.meta.url);
const installers = [
    ['pnpm-lock.yaml', 'pnpm add'],
    ['yarn.lock', 'yarn add'],
    ['bun.lock', 'bun add'],
    ['bun.lockb', 'bun add'],
];

function versionRange() {
    try {
        const manifest = JSON.parse(readFileSync(new URL('../../../package.json', import.meta.url), 'utf8'));
        return manifest.dependencies?.[dependency] ?? null;
    } catch {
        return null;
    }
}

export function installCommand(root = process.cwd()) {
    const installer = installers.find(([lockfile]) => existsSync(resolve(root, lockfile)))?.[1] ?? 'npm install';
    const range = versionRange();
    return `${installer} ${range ? `"${dependency}@${range}"` : dependency}`;
}

/** Composer installs cannot bring npm dependencies, so explain the missing package before Vite fails to resolve it. */
export function assertFrontendDependency(root = process.cwd(), locate = (id) => require.resolve(id)) {
    try {
        locate(`${dependency}/loader`);
    } catch {
        throw new Error(
            `Laravel Vox needs the "${dependency}" npm package, which is not installed. Run: ${installCommand(root)}`
        );
    }
}
