import { execFile } from 'node:child_process';
import { resolve, sep } from 'node:path';

/** Development-only compilation uses the consuming application's Artisan command. */
export function runtimeHotReload(options, enabled) {
    let server;
    let timer;
    let child;
    let running;
    let dirty = false;
    let closed = false;
    let langPath;
    let manifestPath;

    function matches(file) {
        file = resolve(file);
        return file === manifestPath || (file.startsWith(`${langPath}${sep}`) && /\.(php|json)$/u.test(file));
    }

    function compile() {
        return new Promise((resolveCompile, reject) => {
            child = execFile(
                options.phpBinary ?? 'php',
                ['artisan', 'vox:compile', '--no-interaction'],
                { cwd: server.config.root, timeout: 120000, maxBuffer: 1024 * 1024 },
                (error, stdout, stderr) => {
                    child = undefined;
                    if (error)
                        reject(
                            new Error(
                                `[vox] Runtime translation compilation failed: ${stderr || stdout || error.message}`
                            )
                        );
                    else resolveCompile();
                }
            );
        });
    }

    async function drain() {
        if (running || closed) return running;
        running = (async () => {
            while (dirty && !closed) {
                dirty = false;
                try {
                    await compile();
                    if (!closed && !dirty) {
                        server.ws.send({ type: 'custom', event: 'vox:translations-updated', data: {} });
                        server.config.logger.info('[vox] Runtime translations compiled; browsers notified.', {
                            timestamp: true,
                        });
                    }
                } catch (error) {
                    if (!closed) {
                        server.config.logger.error(error.message);
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

    function changed(event, file) {
        if (!['add', 'change', 'unlink'].includes(event) || !matches(file) || closed) return;
        dirty = true;
        clearTimeout(timer);
        timer = setTimeout(() => {
            void drain();
        }, 100);
    }

    return {
        name: 'laravel-vox-runtime-hot-reload',
        apply(config, environment) {
            return (
                environment.command === 'serve' &&
                options.runtimeHotReload !== false &&
                enabled(options, config, environment)
            );
        },
        config() {
            return {
                optimizeDeps: {
                    exclude: [
                        '@keypoint-solutions/laravel-vox/vue/runtime',
                        '@laravel-vox/vue.js',
                        '@laravel-vox/runtime.js',
                    ],
                },
            };
        },
        async configureServer(viteServer) {
            server = viteServer;
            langPath = resolve(server.config.root, options.langPath ?? 'lang');
            manifestPath = resolve(server.config.root, options.manifestPath ?? 'storage/vox/frontend.json');
            server.watcher.add([langPath, manifestPath]);
            server.watcher.on('all', changed);
            running = compile();
            try {
                await running;
            } finally {
                running = undefined;
            }
            if (dirty) await drain();
        },
        hotUpdate: {
            order: 'pre',
            handler(context) {
                if (matches(context.file)) return [];
            },
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
