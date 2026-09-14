import { execFile } from 'node:child_process';
import { existsSync } from 'node:fs';
import { resolve, sep } from 'node:path';

/** Refresh selection with the PHP parser; language values and database state remain untouched. */
export function frontendDiscovery(options, runtimeEnabled) {
    let config;
    let environment;
    let server;
    let paths = [];
    let extensions = [];
    let timer;
    let running;
    let child;
    let dirty = false;
    let closed = false;
    let enabled = false;

    function artisan(command) {
        return new Promise((resolveCommand, reject) => {
            child = execFile(
                options.phpBinary ?? 'php',
                ['artisan', command, '--no-interaction'],
                { cwd: config.root, timeout: 120000, maxBuffer: 1024 * 1024 },
                (error, stdout, stderr) => {
                    child = undefined;
                    if (error) reject(new Error(`[vox] ${command} failed: ${stderr || stdout || error.message}`));
                    else resolveCommand(stdout);
                }
            );
        });
    }

    async function discover() {
        const result = JSON.parse(await artisan('vox:frontend-discover'));
        paths = result.paths.map((path) => resolve(config.root, path));
        extensions = result.extensions;
    }

    async function drain() {
        if (running || closed) return running;
        running = (async () => {
            while (dirty && !closed) {
                dirty = false;
                try {
                    await discover();
                } catch (error) {
                    if (!closed) {
                        config.logger.error(error.message);
                        server.ws.send({
                            type: 'custom',
                            event: 'vox:translations-error',
                            data: { message: error.message },
                        });
                    }
                }
            }
        })();
        try {
            await running;
        } finally {
            running = undefined;
        }
    }

    function changed(event, filename) {
        if (closed || !['add', 'change', 'unlink'].includes(event)) return;
        const file = resolve(filename);
        if (
            !paths.some((path) => file === path || file.startsWith(`${path}${sep}`)) ||
            !extensions.some((extension) => file.endsWith(`.${extension}`))
        )
            return;
        dirty = true;
        clearTimeout(timer);
        timer = setTimeout(() => {
            void drain();
        }, 100);
    }

    return {
        name: 'laravel-vox-frontend-discovery',
        config(_config, env) {
            environment = env;
        },
        async configResolved(resolved) {
            config = resolved;
            enabled = options.frontendDiscovery !== false && existsSync(resolve(config.root, 'artisan'));
            if (!enabled) return;
            await discover();
            if (environment.command === 'build' && runtimeEnabled(options, config, environment)) {
                await artisan('vox:compile');
            }
        },
        configureServer(viteServer) {
            if (!enabled) return;
            server = viteServer;
            server.watcher.add(paths);
            server.watcher.on('all', changed);
        },
        async closeBundle() {
            closed = true;
            clearTimeout(timer);
            server?.watcher.off('all', changed);
            child?.kill();
            await running;
        },
    };
}
