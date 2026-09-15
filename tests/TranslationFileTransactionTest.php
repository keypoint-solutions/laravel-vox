<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Translation\TranslationFileTransaction;

beforeEach(function (): void {
    $this->transactionPath = base_path('tests/.tmp/transaction-'.Str::uuid());
    File::makeDirectory($this->transactionPath, 0755, true);
});

afterEach(function (): void {
    File::deleteDirectory($this->transactionPath);
});

it('preserves existing file permissions when replacing contents', function (int $mode): void {
    $path = $this->transactionPath.'/messages.php';
    File::put($path, 'original');
    chmod($path, $mode);

    app(TranslationFileTransaction::class)->replace($path, 'updated');
    clearstatcache(true, $path);

    expect(fileperms($path) & 0777)->toBe($mode)
        ->and(File::get($path))->toBe('updated');
})->with([0644, 0664, 0600]);

it('creates files with non executable permissions respecting the umask', function (int $mask, int $expected): void {
    $path = $this->transactionPath.'/fr.json';
    $originalMask = umask($mask);

    try {
        app(TranslationFileTransaction::class)->replace($path, '{}');
        clearstatcache(true, $path);
        expect(fileperms($path) & 0777)->toBe($expected);
    } finally {
        umask($originalMask);
    }
})->with([[0022, 0644], [0002, 0664], [0077, 0600]]);

it('restores original contents and permissions and removes new files on rollback', function (): void {
    $updated = $this->transactionPath.'/updated.php';
    $deleted = $this->transactionPath.'/deleted.php';
    $created = $this->transactionPath.'/new.json';
    File::put($updated, 'original updated');
    File::put($deleted, 'original deleted');
    chmod($updated, 0600);
    chmod($deleted, 0664);
    $transaction = app(TranslationFileTransaction::class);

    expect(fn () => $transaction->run(function () use ($transaction, $updated, $deleted, $created): void {
        $transaction->replace($updated, 'changed');
        $transaction->replace($updated, 'changed again');
        $transaction->delete($deleted);
        $transaction->replace($created, '{}');
        throw new RuntimeException('Publishing failed');
    }))->toThrow(RuntimeException::class, 'Publishing failed');

    clearstatcache();
    expect(File::get($updated))->toBe('original updated')
        ->and(fileperms($updated) & 0777)->toBe(0600)
        ->and(File::get($deleted))->toBe('original deleted')
        ->and(fileperms($deleted) & 0777)->toBe(0664)
        ->and(File::exists($created))->toBeFalse();
});
