import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, rmSync, readdirSync, realpathSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { resolve, dirname } from 'node:path';
import { test } from 'node:test';
import { parse } from 'laravel-vue-i18n/loader';
import { build, createServer } from 'vite';
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
        configFile: false, root, logLevel: 'silent', plugins: vox({ frontendGroups: ['*'] }),
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
        configFile: false, root, logLevel: 'silent', plugins: vox({ frontendGroups: ['*'] }),
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
