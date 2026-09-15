<?php

namespace KeypointSolutions\LaravelVox\Translation;

use Illuminate\Support\Facades\File;
use JsonException;
use ParseError;
use RuntimeException;

class TranslationFileValidator
{
    public function validateFile(string $path): void
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $contents = File::get($path);

        if ($extension === 'php') {
            $this->validatePhp($contents, $path);

            return;
        }

        if ($extension === 'json') {
            $this->validateJson($contents, $path);

            return;
        }

        throw new RuntimeException("Translation file [{$path}] must be PHP or JSON.");
    }

    public function validateContents(string $contents, string $relativePath): void
    {
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

        if ($extension === 'php') {
            $this->validatePhp($contents, $relativePath);

            return;
        }

        if ($extension === 'json') {
            $this->validateJson($contents, $relativePath);

            return;
        }

        throw new RuntimeException("Translation file [{$relativePath}] must be PHP or JSON.");
    }

    public function validateDirectory(string $path): void
    {
        if (! File::isDirectory($path)) {
            return;
        }

        foreach (File::allFiles($path) as $file) {
            if (! in_array(strtolower($file->getExtension()), ['php', 'json'], true)) {
                continue;
            }

            $this->validateFile($file->getPathname());
        }
    }

    public function assertTranslationPath(string $path): void
    {
        $normalized = trim(str_replace('\\', '/', $path), '/');
        $segment = '[A-Za-z0-9][A-Za-z0-9_.-]*';
        $locale = '[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})*';
        $matchesRootJson = preg_match('/^'.$locale.'\.json$/D', $normalized) === 1;
        $matchesPhpGroup = preg_match('/^'.$locale.'\/(?:'.$segment.'\/)*'.$segment.'\.php$/D', $normalized) === 1;
        $matchesVendorPhp = preg_match(
            '/^vendor\/'.$segment.'\/'.$locale.'\/(?:'.$segment.'\/)*'.$segment.'\.php$/D',
            $normalized
        ) === 1;
        $matchesVendorJson = preg_match(
            '/^vendor\/'.$segment.'\/'.$locale.'\.json$/D',
            $normalized
        ) === 1;

        if (! $matchesRootJson && ! $matchesPhpGroup && ! $matchesVendorPhp && ! $matchesVendorJson) {
            throw new RuntimeException("Archive entry [{$path}] is not a supported translation file.");
        }
    }

    private function validatePhp(string $contents, string $path): void
    {
        try {
            $tokens = token_get_all($contents, TOKEN_PARSE);
        } catch (ParseError $exception) {
            throw new RuntimeException("Translation file [{$path}] contains invalid PHP.", previous: $exception);
        }

        $allowedTokenIds = [
            T_OPEN_TAG,
            T_RETURN,
            T_ARRAY,
            T_CONSTANT_ENCAPSED_STRING,
            T_LNUMBER,
            T_DNUMBER,
            T_DOUBLE_ARROW,
            T_WHITESPACE,
            T_COMMENT,
            T_DOC_COMMENT,
        ];
        $allowedCharacters = ['[', ']', '(', ')', ',', ';', '.'];
        $literalTokens = [];

        foreach ($tokens as $token) {
            if (is_string($token)) {
                if (! in_array($token, $allowedCharacters, true)) {
                    $this->rejectExecutablePhp($path);
                }

                $literalTokens[] = $token;

                continue;
            }

            if (! in_array($token[0], $allowedTokenIds, true)) {
                $this->rejectExecutablePhp($path);
            }

            if (! in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $literalTokens[] = $token;
            }
        }

        $position = 0;
        $this->expectToken($literalTokens, $position, T_RETURN, $path);
        $values = $this->parseArray($literalTokens, $position, $path);

        if (($literalTokens[$position] ?? null) === ';') {
            $position++;
        }

        if ($position !== count($literalTokens)) {
            throw new RuntimeException("Translation file [{$path}] must contain one literal returned array.");
        }

        $this->assertTranslationArray($values, $path);
    }

    private function validateJson(string $contents, string $path): void
    {
        try {
            $values = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Translation file [{$path}] contains invalid JSON.", previous: $exception);
        }

        $this->assertTranslationArray($values, $path);
    }

    private function assertTranslationArray(mixed $values, string $path): void
    {
        if (! is_array($values)) {
            throw new RuntimeException("Translation file [{$path}] must return a translation array.");
        }

        foreach ($values as $key => $value) {
            if (! is_string($key)) {
                throw new RuntimeException("Translation file [{$path}] must use string keys.");
            }

            if (is_array($value)) {
                $this->assertTranslationArray($value, $path);

                continue;
            }

            if (! is_string($value)) {
                throw new RuntimeException(
                    "Translation file [{$path}] must contain only string values or nested translation arrays."
                );
            }
        }
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     * @return array<string, string|array<mixed>>
     */
    private function parseArray(array $tokens, int &$position, string $path): array
    {
        $token = $tokens[$position] ?? null;

        if ($this->tokenIs($token, T_ARRAY)) {
            $position++;
            $this->expectCharacter($tokens, $position, '(', $path);
            $closingCharacter = ')';
        } elseif ($token === '[') {
            $position++;
            $closingCharacter = ']';
        } else {
            throw new RuntimeException("Translation file [{$path}] must return a literal array.");
        }

        $values = [];

        while (($tokens[$position] ?? null) !== $closingCharacter) {
            $keyToken = $tokens[$position] ?? null;

            if (! $this->tokenIs($keyToken, T_CONSTANT_ENCAPSED_STRING)) {
                throw new RuntimeException("Translation file [{$path}] must use string keys.");
            }

            $key = $this->decodeStringLiteral($keyToken[1], $path);
            $position++;
            $this->expectToken($tokens, $position, T_DOUBLE_ARROW, $path);
            $valueToken = $tokens[$position] ?? null;

            if ($this->tokenIs($valueToken, T_CONSTANT_ENCAPSED_STRING)) {
                $values[$key] = $this->decodeStringLiteral($valueToken[1], $path);
                $position++;

                while (($tokens[$position] ?? null) === '.') {
                    $position++;
                    $part = $tokens[$position] ?? null;

                    if (! $this->tokenIs($part, T_CONSTANT_ENCAPSED_STRING)) {
                        $this->rejectExecutablePhp($path);
                    }

                    $values[$key] .= $this->decodeStringLiteral($part[1], $path);
                    $position++;
                }
            } elseif ($valueToken === '[' || $this->tokenIs($valueToken, T_ARRAY)) {
                $values[$key] = $this->parseArray($tokens, $position, $path);
            } else {
                throw new RuntimeException(
                    "Translation file [{$path}] must contain only string values or nested translation arrays."
                );
            }

            if (($tokens[$position] ?? null) === ',') {
                $position++;
            } elseif (($tokens[$position] ?? null) !== $closingCharacter) {
                throw new RuntimeException("Translation file [{$path}] contains an invalid array.");
            }
        }

        $position++;

        return $values;
    }

    private function decodeStringLiteral(string $literal, string $path): string
    {
        $quote = $literal[0] ?? '';

        if ($quote === "'" && str_ends_with($literal, "'")) {
            return str_replace(
                ['\\\\', "\\'"],
                ['\\', "'"],
                substr($literal, 1, -1)
            );
        }

        if ($quote === '"' && str_ends_with($literal, '"')) {
            return stripcslashes(substr($literal, 1, -1));
        }

        throw new RuntimeException("Translation file [{$path}] contains an invalid string literal.");
    }

    /**
     * @param  array{0: int, 1: string, 2: int}|string|null  $token
     */
    private function tokenIs(array|string|null $token, int $id): bool
    {
        return is_array($token) && $token[0] === $id;
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function expectToken(array $tokens, int &$position, int $id, string $path): void
    {
        if (! $this->tokenIs($tokens[$position] ?? null, $id)) {
            throw new RuntimeException("Translation file [{$path}] must contain one literal returned array.");
        }

        $position++;
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function expectCharacter(array $tokens, int &$position, string $character, string $path): void
    {
        if (($tokens[$position] ?? null) !== $character) {
            throw new RuntimeException("Translation file [{$path}] contains an invalid array.");
        }

        $position++;
    }

    private function rejectExecutablePhp(string $path): never
    {
        throw new RuntimeException(
            "Translation file [{$path}] contains executable PHP. Only literal arrays, strings, and string literal concatenation are allowed."
        );
    }
}
