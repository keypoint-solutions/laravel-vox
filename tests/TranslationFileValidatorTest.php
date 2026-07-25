<?php

use KeypointSolutions\LaravelVox\Translation\TranslationFileValidator;

it('accepts literal nested PHP and JSON translation arrays', function (): void {
    $validator = app(TranslationFileValidator::class);

    $validator->validateContents(<<<'PHP'
<?php

// A normal translation file.
return [
    'greeting' => 'Hello :name',
    'nested' => array(
        'message' => "Welcome\nback",
    ),
];
PHP, 'en/messages.php');
    $validator->validateContents(
        '{"greeting":"Hello :name","nested":{"message":"Welcome back"}}',
        'en.json'
    );

    expect(true)->toBeTrue();
});

it('rejects executable PHP translation expressions before they can run', function (string $contents): void {
    expect(fn () => app(TranslationFileValidator::class)->validateContents($contents, 'en/messages.php'))
        ->toThrow(RuntimeException::class, 'contains executable PHP');
})->with([
    'variable' => "<?php return ['message' => \$value];",
    'function call' => "<?php return ['message' => strtoupper('unsafe')];",
    'concatenation' => "<?php return ['message' => 'un'.'safe'];",
    'interpolation' => "<?php return ['message' => \"Hello {\$name}\"];",
    'include' => "<?php return ['message' => include 'payload.php'];",
]);

it('rejects non-string translation keys and values', function (string $contents, string $message): void {
    expect(fn () => app(TranslationFileValidator::class)->validateContents($contents, 'en/messages.php'))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'integer key' => ["<?php return [1 => 'one'];", 'must use string keys'],
    'integer value' => [
        "<?php return ['count' => 1];",
        'must contain only string values or nested translation arrays',
    ],
]);

it('allows only standard Laravel translation archive paths', function (): void {
    $validator = app(TranslationFileValidator::class);

    foreach ([
        'en.json',
        'en/messages.php',
        'vendor/cashier/en/cashier.php',
        'vendor/package/en.json',
    ] as $path) {
        $validator->assertTranslationPath($path);
    }

    expect(fn () => $validator->assertTranslationPath('config/app.php'))
        ->toThrow(RuntimeException::class, 'not a supported translation file');
});
