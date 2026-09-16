import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { chmodSync, copyFileSync, existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const source = join(dirname(fileURLToPath(import.meta.url)), '../.husky');
const hooks = ['post-commit', 'pre-push', 'post-merge', 'post-checkout', 'post-rewrite', 'sync-private-docs', 'sync-private-docs.mjs'];

function fixture(t, { enabled = true, withDocs = true } = {}) {
    const base = mkdtempSync(join(tmpdir(), 'vox-hooks-'));
    t.after(() => rmSync(base, { recursive: true, force: true }));
    const env = { ...process.env, GIT_CONFIG_GLOBAL: '/dev/null', GIT_CONFIG_NOSYSTEM: '1', HUSKY: '0' };
    for (const key of Object.keys(env)) if (key.startsWith('GIT_') && !['GIT_CONFIG_GLOBAL', 'GIT_CONFIG_NOSYSTEM'].includes(key)) delete env[key];
    function git(cwd, ...args) {
        const result = spawnSync('git', args, { cwd, env, encoding: 'utf8', timeout: 10000 });
        assert.equal(result.status, 0, `${args.join(' ')}: ${result.stderr}`);
        return result.stdout.trim();
    }
    function init(path, bare = false) {
        mkdirSync(path, { recursive: true });
        git(path, 'init', '-b', 'main', ...(bare ? ['--bare'] : []));
        if (!bare) {
            git(path, 'config', 'user.name', 'Hook Test');
            git(path, 'config', 'user.email', 'hooks@example.test');
        }
    }
    const root = join(base, 'public');
    init(root);
    writeFileSync(join(root, '.gitignore'), '_internal/\n');
    git(root, 'add', '.gitignore');
    git(root, 'commit', '-m', 'Initial public commit');
    mkdirSync(join(root, '.husky'));
    for (const hook of hooks) {
        copyFileSync(join(source, hook), join(root, '.husky', hook));
        chmodSync(join(root, '.husky', hook), 0o755);
    }
    git(root, 'config', 'core.hooksPath', '.husky');
    if (enabled) git(root, 'config', 'vox.syncPrivateDocs', 'true');
    const docs = join(root, '_internal/docs');
    const remote = join(base, 'docs.git');
    const peer = join(base, 'peer');
    if (withDocs) {
        init(remote, true);
        init(docs);
        writeFileSync(join(docs, 'SPEC.md'), 'Original spec\n');
        git(docs, 'add', 'SPEC.md');
        git(docs, 'commit', '-m', 'Initial spec');
        git(docs, 'remote', 'add', 'origin', remote);
        git(docs, 'push', '-u', 'origin', 'main');
        git(base, 'clone', remote, peer);
        git(peer, 'config', 'user.name', 'Peer');
        git(peer, 'config', 'user.email', 'peer@example.test');
    }
    function hook(name, ...args) {
        const result = spawnSync('sh', [join(root, '.husky', name), ...args], { cwd: root, env, encoding: 'utf8', timeout: 10000 });
        assert.equal(result.status, 0, result.stderr);
        return result;
    }
    function peerCommit() {
        writeFileSync(join(peer, 'SPEC.md'), 'Remote spec\n');
        git(peer, 'commit', '-am', 'Remote update');
        git(peer, 'push');
    }
    return { base, root, docs, remote, peer, git, hook, peerCommit };
}

test('public forks are silent no-ops without opt-in, even with docs present', (t) => {
    const f = fixture(t, { enabled: false });
    writeFileSync(join(f.docs, 'SPEC.md'), 'Local edit\n');
    for (const name of ['post-commit', 'pre-push', 'post-merge']) assert.equal(f.hook(name).stderr, '');
    assert.equal(f.git(f.docs, 'log', '-1', '--format=%s'), 'Initial spec');
});

test('opted-in checkout without private repo is a silent no-op', (t) => {
    const f = fixture(t, { withDocs: false });
    for (const name of ['post-commit', 'pre-push', 'post-merge']) assert.equal(f.hook(name).stderr, '');
    assert.equal(existsSync(f.docs), false);
});

test('real public commit saves only SPEC and preserves the private index', (t) => {
    const f = fixture(t);
    writeFileSync(join(f.docs, 'other.txt'), 'Keep staged\n');
    f.git(f.docs, 'add', 'other.txt');
    writeFileSync(join(f.docs, 'SPEC.md'), 'Updated spec\n');
    f.git(f.root, 'commit', '--allow-empty', '-m', 'Public change');
    assert.equal(f.git(f.docs, 'show', '--format=', '--name-only', 'HEAD'), 'SPEC.md');
    assert.equal(f.git(f.docs, 'diff', '--cached', '--name-only'), 'other.txt');
    assert.equal(f.git(f.root, 'log', '-1', '--format=%s'), 'Public change');
});

test('pre-push commits spec edits and pushes to the private upstream', (t) => {
    const f = fixture(t);
    writeFileSync(join(f.docs, 'SPEC.md'), 'Ready to push\n');
    f.hook('pre-push');
    assert.equal(f.git(f.remote, 'show', 'main:SPEC.md'), 'Ready to push');
});

test('merge, rebase and branch checkout pull remote updates; file checkout does not', (t) => {
    for (const [name, args] of [['post-merge', ['0']], ['post-rewrite', ['rebase']], ['post-checkout', ['a', 'b', '1']]]) {
        const f = fixture(t);
        f.peerCommit();
        f.hook('post-checkout', 'a', 'b', '0');
        assert.equal(readFileSync(join(f.docs, 'SPEC.md'), 'utf8'), 'Original spec\n');
        f.hook(name, ...args);
        assert.equal(readFileSync(join(f.docs, 'SPEC.md'), 'utf8'), 'Remote spec\n');
    }
});

test('dirty or divergent docs are preserved and never block hooks', (t) => {
    const f = fixture(t);
    f.peerCommit();
    writeFileSync(join(f.docs, 'SPEC.md'), 'Local spec\n');
    assert.match(f.hook('post-merge').stderr, /uncommitted changes/);
    f.hook('post-commit');
    const before = f.git(f.docs, 'rev-parse', 'HEAD');
    assert.match(f.hook('pre-push').stderr, /failed/);
    assert.equal(f.git(f.docs, 'rev-parse', 'HEAD'), before);
    assert.equal(readFileSync(join(f.docs, 'SPEC.md'), 'utf8'), 'Local spec\n');
    assert.equal(f.git(f.remote, 'show', 'main:SPEC.md'), 'Remote spec');
});

test('unreachable private remote does not block an actual public push', (t) => {
    const f = fixture(t);
    f.git(f.docs, 'remote', 'set-url', 'origin', join(f.base, 'unavailable.git'));
    const publicRemote = join(f.base, 'public.git');
    f.git(f.base, 'init', '--bare', publicRemote);
    f.git(f.root, 'remote', 'add', 'origin', publicRemote);
    f.git(f.root, 'push', '-u', 'origin', 'main');
    assert.equal(f.git(publicRemote, 'rev-parse', 'main'), f.git(f.root, 'rev-parse', 'HEAD'));
});

test('detached HEAD and unfinished private operations are skipped', (t) => {
    const f = fixture(t);
    f.git(f.docs, 'checkout', '--detach');
    assert.match(f.hook('post-commit').stderr, /detached HEAD/);
    f.git(f.docs, 'checkout', 'main');
    writeFileSync(join(f.docs, '.git/MERGE_HEAD'), f.git(f.docs, 'rev-parse', 'HEAD'));
    assert.match(f.hook('pre-push').stderr, /unfinished Git operation/);
});
