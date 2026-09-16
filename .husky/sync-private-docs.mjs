import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, rmdirSync } from 'node:fs';
import { resolve } from 'node:path';

const root = process.cwd();
const docs = resolve(root, '_internal/docs');
const environment = { ...process.env };
const warn = (message) => console.error(`vox docs: ${message}; continuing main Git operation.`);

function git(cwd, args, required = true) {
    const result = spawnSync('git', args, {
        cwd,
        env: environment,
        encoding: 'utf8',
        timeout: 20000,
        killSignal: 'SIGKILL',
        stdio: ['ignore', 'pipe', 'pipe'],
    });
    if (required && result.status !== 0) {
        throw new Error(`git ${args[0]} failed${result.error?.code === 'ETIMEDOUT' ? ' (20s timeout)' : ''}. Run git -C _internal/docs status and sync manually`);
    }
    return result.status === 0 ? result.stdout.trim() : null;
}

let lock;
try {
    if (git(root, ['config', '--local', '--bool', '--get', 'vox.syncPrivateDocs'], false) !== 'true') process.exit(0);
    if (!existsSync(resolve(docs, '.git'))) process.exit(0);

    // Git exports the parent index/worktree context to hooks; never reuse it in docs.
    for (const name of git(root, ['rev-parse', '--local-env-vars']).split('\n')) delete environment[name];
    for (const name of Object.keys(environment)) {
        if (/^GIT_(AUTHOR|COMMITTER)_/.test(name)) delete environment[name];
    }
    Object.assign(environment, {
        GIT_TERMINAL_PROMPT: '0',
        GCM_INTERACTIVE: 'never',
        GIT_ASKPASS: 'true',
        SSH_ASKPASS: 'true',
        GIT_SSH_COMMAND: 'ssh -o BatchMode=yes -o ConnectTimeout=10',
        HUSKY: '0',
    });

    if (git(docs, ['rev-parse', '--show-toplevel']) !== docs) throw new Error('private docs path is not a standalone checkout');
    const gitDir = git(docs, ['rev-parse', '--absolute-git-dir']);
    const lockPath = resolve(gitDir, 'vox-sync.lock');
    if (existsSync(lockPath)) throw new Error('docs sync is already running; if interrupted, remove _internal/docs/.git/vox-sync.lock');
    mkdirSync(lockPath);
    lock = lockPath;

    for (const marker of ['MERGE_HEAD', 'CHERRY_PICK_HEAD', 'REVERT_HEAD', 'rebase-merge', 'rebase-apply', 'sequencer', 'index.lock']) {
        if (existsSync(resolve(gitDir, marker))) throw new Error('private docs has an unfinished Git operation');
    }
    const branch = git(docs, ['symbolic-ref', '--quiet', '--short', 'HEAD'], false);
    if (!branch) throw new Error('private docs has a detached HEAD');

    const action = process.argv[2];
    if (!['commit', 'pull', 'push'].includes(action)) throw new Error('expected commit, pull, or push');
    if (action === 'commit' || action === 'push') {
        const changes = git(docs, ['status', '--porcelain', '--', 'SPEC.md']);
        if (changes) {
            git(docs, ['ls-files', '--error-unmatch', '--', 'SPEC.md']);
            const revision = git(root, ['rev-parse', '--short', 'HEAD']);
            git(docs, ['commit', '--only', '-m', `Update specification for laravel-vox ${revision}`, '--', 'SPEC.md']);
            console.error('vox docs: committed SPEC.md.');
        }
    }
    if (action !== 'commit') {
        if (git(docs, ['status', '--porcelain'])) throw new Error('private docs has uncommitted changes; sync skipped');
        const remote = git(docs, ['config', '--get', `branch.${branch}.remote`]);
        const ref = git(docs, ['config', '--get', `branch.${branch}.merge`]);
        if (!remote || remote === '.' || !ref.startsWith('refs/heads/')) throw new Error('private docs needs a remote branch upstream');
        git(docs, ['-c', 'gc.auto=0', 'fetch', '--no-tags', remote, ref]);
        git(docs, ['merge', '--ff-only', 'FETCH_HEAD']);
        if (action === 'push') git(docs, ['push', '--no-follow-tags', remote, `HEAD:${ref}`]);
    }
} catch (error) {
    warn(error.message);
} finally {
    if (lock) rmdirSync(lock);
}
