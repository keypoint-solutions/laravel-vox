<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Translation\TranslationFileRepository;
use KeypointSolutions\LaravelVox\Translation\TranslationFileValidator;
use KeypointSolutions\LaravelVox\Translation\TranslationFileWriter;

it('keeps multiline obsolete entries on one valid comment line', function (): void {
    $writer = app(TranslationFileWriter::class);
    $validator = app(TranslationFileValidator::class);
    $key = "First line\nSecond line";
    $value = "First value\nSecond value";
    $contents = $writer->toPhp([], [$key => $value]);

    $validator->validateContents($contents, 'en/messages.php');

    expect($contents)
        ->toContain('"First line\\nSecond line" => "First value\\nSecond value"')
        ->not->toContain("// 🗑️ \"First line\n");
});

it('recognizes JSON encoded obsolete keys from generated files', function (): void {
    $path = base_path('tests/.tmp/writer-'.Str::uuid());
    File::makeDirectory($path.'/en', 0755, true);
    $files = app(TranslationFileRepository::class)->forPath($path);
    $key = "First line\nSecond line";

    try {
        $files->saveGroup('en', 'messages', [], [$key => 'Obsolete value']);

        expect($files->loadObsoleteComments('en', 'messages'))->toHaveKey($key);
    } finally {
        File::deleteDirectory($path);
    }
});

it('rejects invalid UTF-8 JSON instead of replacing an existing translation file', function (): void {
    $path = base_path('tests/.tmp/writer-'.Str::uuid());
    File::ensureDirectoryExists($path);
    $files = app(TranslationFileRepository::class)->forPath($path);
    $files->saveJson('en', ['Greeting' => 'Hello']);
    $before = File::get($path.'/en.json');
    try {
        expect(fn () => $files->saveJson('en', ['Greeting' => "\xC3\x28"]))->toThrow(JsonException::class);
        expect(File::get($path.'/en.json'))->toBe($before);
    } finally {
        File::deleteDirectory($path);
    }
});

it('round trips Unicode JSON in readable and escaped modes', function (bool $escaped): void {
    config()->set('vox.parse.escape_unicode', $escaped);
    $values = ['é' => 'Grüße العربية 日本語 🚩'];
    $json = app(TranslationFileWriter::class)->toJson($values);
    expect(json_decode($json, true, flags: JSON_THROW_ON_ERROR))->toBe($values)
        ->and(str_starts_with($json, "\xEF\xBB\xBF"))->toBeFalse();
})->with([false, true]);
