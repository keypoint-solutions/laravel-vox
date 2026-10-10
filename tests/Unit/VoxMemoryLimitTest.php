<?php

use Symfony\Component\Process\Process;

function probeVoxMemoryLimit(mixed $configured, string $existing, bool $disableIniSet = false): array
{
    $script = <<<'PHP'
require $argv[1];
$app = new Illuminate\Foundation\Application($argv[2]);
$app->instance('config', new Illuminate\Config\Repository(['vox' => [
    'memory_limit' => json_decode($argv[3], true),
    'deployment' => ['lock_path' => $argv[4]],
]]));
$app->instance('files', new Illuminate\Filesystem\Filesystem);
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$app->instance(KeypointSolutions\LaravelVox\Translation\TranslationCheckpoints::class,
    new class extends KeypointSolutions\LaravelVox\Translation\TranslationCheckpoints {
        public function run(string $label, Closure $callback): mixed
        {
            return ['checkpoint_limit' => ini_get('memory_limit'), 'result' => $callback()];
        }
    }
);
$lock = new KeypointSolutions\LaravelVox\Support\VoxMutationLock;
try {
    $result = $lock->run(fn (): string => 'done');
} catch (Throwable $error) {
    $app['config']->set('vox.memory_limit', null);
    $result = ['error' => $error->getMessage(), 'retry' => $lock->run(fn (): string => 'retry')];
}
echo json_encode($result, JSON_THROW_ON_ERROR);
PHP;
    $lockPath = test()->voxMemoryLockPath;
    $process = new Process([
        PHP_BINARY, '-d', 'memory_limit='.$existing,
        '-d', 'disable_functions='.($disableIniSet ? 'ini_set' : ''),
        '-r', $script,
        dirname(__DIR__, 2).'/vendor/autoload.php', dirname(__DIR__, 2),
        json_encode($configured, JSON_THROW_ON_ERROR), $lockPath,
    ]);
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    $this->voxMemoryLockPath = sys_get_temp_dir().'/vox-memory-test-'.bin2hex(random_bytes(8)).'.lock';
});

afterEach(function (): void {
    if (is_file($this->voxMemoryLockPath)) {
        unlink($this->voxMemoryLockPath);
    }
});

it('raises the default limit before checkpoint capture', function (): void {
    expect(config('vox.memory_limit'))->toBe('256M')
        ->and(probeVoxMemoryLimit(config('vox.memory_limit'), '128M'))
        ->toBe(['checkpoint_limit' => '256M', 'result' => 'done']);
});

it('accepts configured memory limits', function (mixed $configured, string $expected): void {
    expect(probeVoxMemoryLimit($configured, '128M')['checkpoint_limit'])->toBe($expected);
})->with([
    ['384M', '384M'],
    [536870912, '536870912'],
    [-1, '-1'],
]);

it('preserves higher and unlimited existing limits', function (string $existing): void {
    expect(probeVoxMemoryLimit('256M', $existing)['checkpoint_limit'])->toBe($existing);
})->with(['512M', '-1']);

it('can leave memory management to PHP', function (): void {
    expect(probeVoxMemoryLimit(null, '128M')['checkpoint_limit'])->toBe('128M');
});

it('rejects invalid configuration and releases the lock', function (): void {
    $result = probeVoxMemoryLimit('256MB', '128M');
    expect($result['error'])->toContain('Invalid vox.memory_limit')
        ->and($result['retry']['result'])->toBe('retry');
});

it('reports a refused memory increase and releases the lock', function (): void {
    $result = probeVoxMemoryLimit('256M', '128M', disableIniSet: true);
    expect($result['error'])->toContain('Unable to raise PHP memory_limit to 256M')
        ->and($result['retry']['result'])->toBe('retry');
});
