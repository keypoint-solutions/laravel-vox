<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Support\VoxArchive;

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
