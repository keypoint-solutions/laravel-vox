import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, rmSync, readdirSync, realpathSync, readFileSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { resolve, dirname } from 'node:path';
import { test } from 'node:test';
import { parse } from 'laravel-vue-i18n/loader';
import { build, createServer, resolveConfig } from 'vite';
import vox from '../resources/js/consumer/vite.js';
import { PhpTranslationCatalogue } from '../resources/js/consumer/php-catalogue.js';

const phpId = (locale) => `\0virtual:laravel-vox/php/${locale}`;
const catalogueId = '\0virtual:laravel-vox/translations';
function fixture(t) {
    const root = realpathSync(mkdtempSync(resolve(tmpdir(), 'vox-vite-')));
    t.after(() => rmSync(root, { recursive: true, force: true }));
    const write = (file, text) => {
        const path = resolve(root, file);
        mkdirSync(dirname(path), { recursive: true });
        writeFileSync(path, text);
        return path;
    };
    write('lang/en/messages.php', "<?php return ['hello' => 'Hello'];");
    write('lang/fr/messages.php', "<?php return ['hello' => 'Bonjour'];");
    return { root, write };
}
function plugin(root, options = { frontendGroups: ['*'] }) {
    const p = vox(options)[1];
    p.configResolved({ root });
    p.buildStart();
    let timestamp = 0;
    const update = (file, contents, type = 'update', ids = [phpId('en'), phpId('fr'), catalogueId], time) => {
        const nodes = new Map(ids.map(id => [id, { id }]));
        return p.hotUpdate.handler.call({ environment: { moduleGraph: { getModuleById: id => nodes.get(id) } } }, {
            file, type, timestamp: time ?? ++timestamp, modules: [{ id: file }], read: async () => contents,
        }).then(modules => modules.map(module => module.id));
    };
    return { p, update };
}

test('parses only changed files, reuses unchanged contents, and retains cache after invalid edits', t => {
    const { root, write } = fixture(t);
    let parses = 0;
    const catalogue = new PhpTranslationCatalogue([resolve(root, 'lang')], source => { parses++; return parse(source); });
    catalogue.initialize();
    assert.equal(parses, 2);
    const french = write('lang/fr/messages.php', "<?php return ['hello' => 'Salut'];");
    catalogue.update(french);
    assert.equal(parses, 3);
    assert.equal(catalogue.messages('fr', ['*'])['messages.hello'], 'Salut');
    assert.equal(catalogue.messages('en', ['*'])['messages.hello'], 'Hello');
    assert.equal(catalogue.update(french), null);
    assert.equal(parses, 3);
    assert.throws(() => catalogue.update(french, '<?php return ['));
    assert.equal(catalogue.messages('fr', ['*'])['messages.hello'], 'Salut');
    assert.equal(catalogue.update(french), null);
});

test('merges framework, application, additional roots and namespaced overrides per key', t => {
    const { root, write } = fixture(t);
    write('framework/en/messages.php', "<?php return ['hello'=>'Framework', 'retained'=>'Retained'];");
    write('lang/vendor/shop/en/messages.php', "<?php return ['hello'=>'Shop'];");
    const additional = write('extra/en/messages.php', "<?php return ['hello'=>'Additional'];");
    const catalogue = new PhpTranslationCatalogue(['framework', 'lang', 'extra'].map(path => resolve(root, path)));
    catalogue.initialize();
    assert.deepEqual(catalogue.messages('en', ['*']), {
        'messages.hello': 'Additional', 'messages.retained': 'Retained', 'shop::messages.hello': 'Shop',
    });
    assert.deepEqual(catalogue.messages('en', ['shop::messages']), { 'shop::messages.hello': 'Shop' });
    catalogue.update(additional, null);
    assert.equal(catalogue.messages('en', ['*'])['messages.hello'], 'Hello');
    assert.equal(catalogue.describe(resolve(root, 'elsewhere/en/messages.php')), null);
});

test('updates just the changed locale and shares the event across environment module graphs', async t => {
    const { root } = fixture(t);
    const { p, update } = plugin(root);
    const englishBefore = p.load(phpId('en'));
    const file = resolve(root, 'lang/fr/messages.php');
    const source = "<?php return ['hello' => 'Salut'];";
    assert.deepEqual(await update(file, source, 'update', undefined, 100), [phpId('fr')]);
    assert.deepEqual(await update(file, source, 'update', undefined, 100), [phpId('fr')]);
    assert.equal(p.load(phpId('en')), englishBefore);
    assert.deepEqual(await update(file, source), []);
    assert.deepEqual(await update(file, source + '\n'), []);
    assert.deepEqual(readdirSync(resolve(root, 'lang')).sort(), ['en', 'fr']);
});

test('updates locale inventory on creation/deletion and JSON edits without touching PHP modules', async t => {
    const { root, write } = fixture(t);
    const { p, update } = plugin(root);
    const file = write('lang/de/messages.php', "<?php return ['hello'=>'Hallo'];");
    assert.deepEqual(await update(file, "<?php return ['hello'=>'Hallo'];", 'create', [phpId('de'), catalogueId]), [phpId('de'), catalogueId]);
    assert.match(p.load(catalogueId), /de/);
    rmSync(file);
    assert.deepEqual(await update(file, null, 'delete', [phpId('de'), catalogueId]), [phpId('de'), catalogueId]);
    const json = write('lang/fr.json', '{"Welcome":"Bienvenue"}');
    assert.deepEqual(await update(json, '{"Welcome":"Bienvenue"}', 'create'), [json, catalogueId]);
    assert.deepEqual(await update(json, '{"Welcome":"Salut"}'), [json]);
    assert.deepEqual(await update(json, '{"Welcome":"Salut"}'), []);
});

test('manifest changes filter cached translations without parsing source again', async t => {
    const { root, write } = fixture(t);
    const manifest = write('storage/vox/frontend.json', '{"groups":["messages"]}');
    const { p, update } = plugin(root, {});
    write('lang/en/messages.php', '<?php invalid syntax [');
    write('storage/vox/frontend.json', '{"groups":[]}');
    assert.deepEqual(await update(manifest, ''), [phpId('en'), phpId('fr')]);
    assert.equal(p.load(phpId('en')), 'export default {};');
});

test('a real Vite build emits separate PHP locale chunks and retains JSON translations', async t => {
    const { root, write } = fixture(t);
    write('lang/en.json', '{"Welcome":"Welcome"}');
    write('main.js', "export { loadVoxLocale } from 'virtual:laravel-vox/translations';");
    const result = await build({
        configFile: false, root, logLevel: 'silent', plugins: vox({ runtime: false, frontendGroups: ['*'] }),
        build: { write: false, minify: false, rolldownOptions: { input: resolve(root, 'main.js'), preserveEntrySignatures: 'strict' } },
    });
    const chunks = result.output.filter(item => item.type === 'chunk');
    assert.ok(chunks.some(chunk => chunk.code.includes('Bonjour') && !chunk.code.includes('Hello')));
    assert.ok(chunks.some(chunk => chunk.code.includes('Hello') && !chunk.code.includes('Bonjour')));
    assert.ok(chunks.some(chunk => chunk.code.includes('Welcome')));
    assert.deepEqual(readdirSync(resolve(root, 'lang')).sort(), ['en', 'en.json', 'fr']);
});


test('Vite dev server sends accepted translation updates without a page reload', async t => {
    const { root, write } = fixture(t);
    write('main.js', "import { createVox } from '@laravel-vox/vue.js'; window.createVox = createVox;");
    const server = await createServer({
        configFile: false, root, logLevel: 'silent', plugins: vox({ runtime: false, frontendGroups: ['*'] }),
        server: { middlewareMode: true, hmr: true, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
    });
    t.after(() => server.close());
    const sent = [];
    server.environments.client.hot.send = payload => sent.push(payload);
    await server.transformRequest('/main.js');
    await server.transformRequest('@laravel-vox/vue.js');
    await server.transformRequest('virtual:laravel-vox/translations');
    await server.transformRequest('virtual:laravel-vox/php/en');
    const french = server.environments.client.moduleGraph.getModuleById(phpId('fr'));
    const waitFor = async predicate => {
        for (let attempt = 0; attempt < 200; attempt++) {
            if (predicate()) return;
            await new Promise(resolve => setTimeout(resolve, 10));
        }
        assert.fail('Vite did not process the translation change');
    };
    const file = write('lang/fr/messages.php', "<?php return ['hello'=>'Salut'];");
    server.watcher.emit('change', file);
    await waitFor(() => french.lastHMRTimestamp > 0);
    assert.equal(sent.filter(event => event.type === 'full-reload').length, 0);
    const loaded = await server.transformRequest('virtual:laravel-vox/php/fr');
    assert.match(loaded.code, /Salut/);
    write('lang/fr/messages.php', "<?php return ['hello'=>'Bonsoir'];");
    server.watcher.emit('change', file);
    await waitFor(() => sent.some(event => event.type === 'update'));
    assert.equal(sent.filter(event => event.type === 'full-reload').length, 0);
    assert.match((await server.transformRequest('virtual:laravel-vox/php/fr')).code, /Bonsoir/);
    assert.match((await server.transformRequest('virtual:laravel-vox/php/en')).code, /Hello/);
});


test('runtime mode resolves the existing Vue entry to runtime delivery without bundling language files', async t => {
    const { root, write } = fixture(t);
    write('entry.js', "export { createVox } from '@laravel-vox/vue.js';");
    const result = await build({
        root,
        configFile: false,
        logLevel: 'silent',
        plugins: vox({ runtime: true }),
        build: {
            write: false,
            lib: { entry: resolve(root, 'entry.js'), formats: ['es'] },
            rolldownOptions: { external: ['vue', 'laravel-vue-i18n'] },
        },
    });
    const code = (Array.isArray(result) ? result : [result]).flatMap(bundle => bundle.output).filter(item => item.type === 'chunk').map(item => item.code).join('\n');
    assert.match(code, /\/vox/u);
    assert.match(code, /credentials/u);
    assert.doesNotMatch(code, /Bonjour/u);
});

for (const scenario of [
    { name: 'unset', value: undefined, expected: true },
    { name: 'enabled', value: 'true', expected: true },
    { name: 'disabled', value: 'false', expected: false },
    { name: 'explicit bundled override', value: 'true', runtime: false, expected: false },
    { name: 'explicit runtime override', value: 'false', runtime: true, expected: true },
    { name: 'mode-specific env directory', value: 'false', modeValue: 'true', envDir: 'environment', expected: true },
]) {
    test(`runtime environment selection: ${scenario.name}`, async t => {
        const { root, write } = fixture(t);
        const directory = scenario.envDir ? `${scenario.envDir}/` : '';
        if (scenario.value !== undefined) write(`${directory}.env`, `VOX_FRONTEND_RUNTIME_ENABLED=${scenario.value}\n`);
        if (scenario.modeValue) write(`${directory}.env.production`, `VOX_FRONTEND_RUNTIME_ENABLED=${scenario.modeValue}\n`);
        const config = await resolveConfig({
            root, configFile: false, logLevel: 'silent', mode: 'production', envDir: scenario.envDir,
            plugins: vox(scenario.runtime === undefined ? {} : { runtime: scenario.runtime }),
        }, 'build');
        const runtimeAlias = config.resolve.alias.find(alias => alias.find === '@laravel-vox/vue.js');
        assert.equal(Boolean(runtimeAlias), scenario.expected);
        if (runtimeAlias) assert.match(runtimeAlias.replacement, /runtime\.js$/);
        assert.equal(config.plugins.some(p => p.name === 'laravel-vox-translations'), !scenario.expected);
        assert.equal(config.env.VOX_FRONTEND_RUNTIME_ENABLED, undefined);
    });
}

test('runtime development compiles startup and watched changes, coalesces writes, reports errors and recovers', async t => {
    const waitFor = async predicate => {
        for (let attempt = 0; attempt < 300; attempt++) {
            if (predicate()) return;
            await new Promise(resolve => setTimeout(resolve, 10));
        }
        assert.fail('Runtime compilation did not complete');
    };
    const { root, write } = fixture(t);
    write('artisan', `
        const fs = require('node:fs');
        const calls = fs.existsSync('calls') ? Number(fs.readFileSync('calls', 'utf8')) : 0;
        fs.writeFileSync('calls', String(calls + 1));
        if (fs.existsSync('fail')) { console.error('Invalid translation'); process.exit(1); }
        setTimeout(() => {}, 80);
    `);
    const errors = [];
    const server = await createServer({
        root, configFile: false, logLevel: 'silent',
        plugins: vox({ runtime: true, frontendDiscovery: false, phpBinary: process.execPath }),
        server: { middlewareMode: true, watch: { ignored: ['**/*'] } },
    });
    t.after(() => server.close());
    const sent = [];
    server.ws.send = payload => sent.push(payload);
    server.config.logger.error = message => errors.push(message);
    const { readFileSync } = await import('node:fs');
    assert.equal(readFileSync(resolve(root, 'calls'), 'utf8'), '1');
    for (let i = 0; i < 5; i++) server.watcher.emit('all', 'change', resolve(root, 'lang/en/messages.php'));
    await waitFor(() => sent.length === 1);
    assert.equal(readFileSync(resolve(root, 'calls'), 'utf8'), '2');
    assert.equal(sent[0].event, 'vox:translations-updated');
    write('fail', '');
    server.watcher.emit('all', 'unlink', resolve(root, 'lang/fr/messages.php'));
    await waitFor(() => sent.length === 2);
    assert.equal(sent[1].event, 'vox:translations-error');
    assert.match(errors[0], /Invalid translation/);
    rmSync(resolve(root, 'fail'));
    server.watcher.emit('all', 'add', resolve(root, 'storage/vox/frontend.json'));
    await waitFor(() => sent.length === 3);
    assert.equal(sent[2].event, 'vox:translations-updated');
    assert.equal(sent.some(event => event.type === 'full-reload'), false);
    await server.close();
    assert.equal(server.watcher.listeners('all').length, 0);
});

test('runtime hot reload can be disabled and never applies during production builds', async t => {
    const { root } = fixture(t);
    for (const [command, options] of [['build', { runtime: true }], ['serve', { runtime: true, runtimeHotReload: false }]]) {
        const config = await resolveConfig({ root, configFile: false, plugins: vox(options) }, command);
        assert.equal(config.plugins.some(p => p.name === 'laravel-vox-runtime-hot-reload'), false);
    }
});

function discoveryFixture(t) {
    const fixtureData = fixture(t);
    fixtureData.write('selection.json', JSON.stringify(['newgroup']));
    fixtureData.write('artisan', `
        const fs = require('node:fs');
        const path = require('node:path');
        const command = process.argv[2];
        fs.appendFileSync('commands', command + '\\n');
        if (fs.existsSync('fail')) { console.error('Discovery failed'); process.exit(1); }
        if (command === 'vox:frontend-discover') {
            const groups = JSON.parse(fs.readFileSync('selection.json', 'utf8'));
            const manifest = JSON.stringify({ groups });
            fs.mkdirSync('storage/vox', { recursive: true });
            if (!fs.existsSync('storage/vox/frontend.json') || fs.readFileSync('storage/vox/frontend.json', 'utf8') !== manifest)
                fs.writeFileSync('storage/vox/frontend.json', manifest);
            console.log(JSON.stringify({ paths: [path.resolve('source')], extensions: ['vue', 'ts'], manifest: path.resolve('storage/vox/frontend.json') }));
        }
    `);
    return fixtureData;
}

test('production discovery runs before bundled modules are compiled and runtime catalogues are compiled afterward', async t => {
    const { root, write } = discoveryFixture(t);
    write('lang/en/newgroup.php', "<?php return ['title' => 'Discovered before build'];");
    write('main.js', "export { loadVoxLocale } from 'virtual:laravel-vox/translations';");
    const result = await build({
        root, configFile: false, logLevel: 'silent', plugins: vox({ runtime: false, phpBinary: process.execPath }),
        build: { write: false, minify: false, lib: { entry: resolve(root, 'main.js'), formats: ['es'] } },
    });
    assert.ok((Array.isArray(result) ? result : [result]).flatMap(bundle => bundle.output)
        .some(item => item.type === 'chunk' && item.code.includes('Discovered before build')));
    assert.equal(readFileSync(resolve(root, 'commands'), 'utf8'), 'vox:frontend-discover\n');
    await resolveConfig({ root, configFile: false, plugins: vox({ runtime: true, phpBinary: process.execPath }) }, 'build');
    assert.equal(readFileSync(resolve(root, 'commands'), 'utf8'), 'vox:frontend-discover\nvox:frontend-discover\nvox:compile\n');
    write('fail', '');
    await assert.rejects(resolveConfig({ root, configFile: false, plugins: vox({ phpBinary: process.execPath }) }, 'build'), /Discovery failed/);
});

test('development discovery batches source edits, handles deletion, retries failures, and ignores unrelated files', async t => {
    const { root, write } = discoveryFixture(t);
    const server = await createServer({
        root, configFile: false, logLevel: 'silent',
        plugins: vox({ runtime: true, runtimeHotReload: false, phpBinary: process.execPath }),
        server: { middlewareMode: true, ws: false, watch: { ignored: ['**/*'] } },
    });
    t.after(() => server.close());
    const sent = [];
    server.ws.send = event => sent.push(event);
    const waitFor = async predicate => {
        for (let attempt = 0; attempt < 300; attempt++) {
            if (predicate()) return;
            await new Promise(resolve => setTimeout(resolve, 10));
        }
        assert.fail('Discovery did not complete');
    };
    const commands = () => readFileSync(resolve(root, 'commands'), 'utf8').trim().split('\n');
    const groups = () => JSON.parse(readFileSync(resolve(root, 'storage/vox/frontend.json'), 'utf8')).groups;
    write('selection.json', JSON.stringify(['appointments']));
    for (let i = 0; i < 5; i++) server.watcher.emit('all', 'change', resolve(root, 'source/Page.vue'));
    server.watcher.emit('all', 'change', resolve(root, 'lang/en/messages.php'));
    server.watcher.emit('all', 'change', resolve(root, 'source/style.css'));
    await waitFor(() => groups().includes('appointments'));
    assert.equal(commands().length, 2);
    write('fail', '');
    server.watcher.emit('all', 'add', resolve(root, 'source/New.vue'));
    await waitFor(() => sent.length === 1);
    assert.equal(sent[0].event, 'vox:translations-error');
    assert.deepEqual(groups(), ['appointments']);
    rmSync(resolve(root, 'fail'));
    write('selection.json', '[]');
    server.watcher.emit('all', 'unlink', resolve(root, 'source/Page.vue'));
    await waitFor(() => groups().length === 0);
    await server.close();
    assert.equal(server.watcher.listeners('all').length, 0);
});

test('discovery can be disabled for externally prepared manifests', async t => {
    const { root } = discoveryFixture(t);
    await resolveConfig({ root, configFile: false, plugins: vox({ frontendDiscovery: false }) }, 'build');
    assert.equal(existsSync(resolve(root, 'commands')), false);
});

test('a discovered manifest change automatically triggers runtime compilation through the file watcher', async t => {
    const { root, write } = discoveryFixture(t);
    write('source/Page.vue', '<template />');
    const server = await createServer({
        root, configFile: false, logLevel: 'silent',
        plugins: vox({ runtime: true, phpBinary: process.execPath }),
        server: { middlewareMode: true, ws: false, watch: { usePolling: true, interval: 25 } },
    });
    t.after(() => server.close());
    const waitFor = async predicate => {
        for (let attempt = 0; attempt < 300; attempt++) {
            if (predicate()) return;
            await new Promise(resolve => setTimeout(resolve, 10));
        }
        assert.fail('Runtime watcher did not receive the discovered manifest: ' + readFileSync(resolve(root, 'commands'), 'utf8') + JSON.stringify(server.watcher.getWatched()));
    };
    await waitFor(() => server.watcher.getWatched()[resolve(root, 'storage/vox')]?.includes('frontend.json'));
    const sent = [];
    server.ws.send = event => sent.push(event);
    write('selection.json', JSON.stringify(['appointments']));
    write('source/Page.vue', "<template>{{ $t('appointments.title') }}</template>");
    await waitFor(() => sent.some(event => event.event === 'vox:translations-updated'));
    assert.deepEqual(JSON.parse(readFileSync(resolve(root, 'storage/vox/frontend.json'), 'utf8')).groups, ['appointments']);
    const commands = readFileSync(resolve(root, 'commands'), 'utf8').trim().split('\n');
    assert.equal(commands.at(-1), 'vox:compile');
    assert.ok(commands.filter(command => command === 'vox:frontend-discover').length >= 2);
});

test('preserves Laravel slash group names for nested ordinary and vendor folders', t => {
    const { root, write } = fixture(t);
    write('lang/en/admin/messages.php', "<?php return ['hello'=>'Admin'];");
    write('lang/vendor/shop/en/admin/messages.php', "<?php return ['hello'=>'Shop admin'];");
    const catalogue = new PhpTranslationCatalogue([resolve(root, 'lang')]);
    catalogue.initialize();
    assert.deepEqual(catalogue.messages('en', ['admin/messages', 'shop::admin/messages']), {
        'admin/messages.hello': 'Admin',
        'shop::admin/messages.hello': 'Shop admin',
    });
});
