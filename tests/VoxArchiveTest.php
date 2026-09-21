<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Support\VoxArchive;
use KeypointSolutions\LaravelVox\Translation\TranslationFileTransaction;

it('rejects archive entries that escape the language directory', function (): void {
    $root = base_path('tests/.tmp/archive-'.Str::uuid());
    $archivePath = $root.'/unsafe.zip';
    $destination = $root.'/lang';
    $escapedPath = $root.'/escaped.php';
    File::makeDirectory($root, 0755, true);

    $zip = new ZipArchive;
    $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('../escaped.php', '<?php return [];');
    $zip->close();

    try {
        expect(fn () => app(VoxArchive::class)->extractArchive($archivePath, $destination))
            ->toThrow(RuntimeException::class, 'unsafe path');
        expect(File::exists($escapedPath))->toBeFalse();
    } finally {
        File::deleteDirectory($root);
    }
});

it('restores earlier files when a later archive write fails', function (): void {
    $root = base_path('tests/.tmp/archive-'.Str::uuid());
    File::ensureDirectoryExists($root.'/lang/en');
    File::put($root.'/lang/en/messages.php', "<?php return ['hello' => 'Original'];");
    $zip = new ZipArchive;
    $zip->open($root.'/import.zip', ZipArchive::CREATE);
    $zip->addFromString('en/messages.php', "<?php return ['hello' => 'Changed'];");
    $zip->addFromString('en/other.php', "<?php return ['hello' => 'New'];");
    $zip->close();
    $original = File::get($root.'/lang/en/messages.php');
    $transaction = new class extends TranslationFileTransaction
    {
        public function replace(string $path, string $contents): void
        {
            parent::replace($path, $contents);
            if (str_ends_with($path, '/other.php')) {
                throw new RuntimeException('Simulated archive write failure');
            }
        }
    };
    app()->instance(TranslationFileTransaction::class, $transaction);

    try {
        expect(fn () => app(VoxArchive::class)->extractArchive($root.'/import.zip', $root.'/lang'))
            ->toThrow(RuntimeException::class, 'Simulated archive write failure');
        expect(File::get($root.'/lang/en/messages.php'))->toBe($original)
            ->and(File::exists($root.'/lang/en/other.php'))->toBeFalse();
    } finally {
        File::deleteDirectory($root);
    }
});
