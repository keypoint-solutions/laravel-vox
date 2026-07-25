<?php

namespace KeypointSolutions\LaravelVox\Translation;

use Illuminate\Support\Facades\File;

class TranslationFileRepository
{
    public function __construct(
        private TranslationFileWriter $writer,
        private ?TranslationFileValidator $validator = null,
        private ?string $path = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function loadGroup(string $locale, string $group): array
    {
        $path = $this->groupPath($locale, $group);

        if (! File::exists($path)) {
            return [];
        }

        $this->validator()->validateFile($path);
        $translations = require $path;

        return is_array($translations) ? $translations : [];
    }

    /**
     * @return array<string, string>
     */
    public function loadJson(string $locale, ?string $namespace = null): array
    {
        $path = $this->jsonPath($locale, $namespace);

        if (! File::exists($path)) {
            return [];
        }

        $this->validator()->validateFile($path);
        $contents = File::get($path);
        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $commented
     * @param  array<string, array<int, string>>  $lineComments
     * @param  array<int, string>  $rawCommented
     */
    public function saveGroup(string $locale, string $group, array $data, array $commented = [], array $lineComments = [], array $rawCommented = []): void
    {
        $path = $this->groupPath($locale, $group);
        $this->ensureDirectory($path);

        $contents = $this->writer->toPhp($data, $commented, $lineComments, $rawCommented);

        $this->validator()->validateContents($contents, $path);
        File::put($path, $contents);
    }

    /**
     * @return array<string, string>
     */
    public function loadObsoleteComments(string $locale, string $group): array
    {
        $path = $this->groupPath($locale, $group);

        if (! File::exists($path)) {
            return [];
        }

        $contents = File::get($path);
        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        $prefix = $this->writer->obsoleteCommentPrefix();
        $comments = [];

        foreach ($lines as $line) {
            if (! is_string($line)) {
                continue;
            }

            if (preg_match('/^\s*\/\/\s*'.preg_quote($prefix, '/').'\s*(.+)$/', $line, $matches) !== 1) {
                continue;
            }

            $payload = trim($matches[1]);
            $key = $this->parseObsoleteCommentKey($payload);

            if ($key === null) {
                continue;
            }

            $comments[$key] = $payload;
        }

        return $comments;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function loadLineComments(string $locale, string $group): array
    {
        $path = $this->groupPath($locale, $group);

        if (! File::exists($path)) {
            return [];
        }

        $contents = File::get($path);
        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        $comments = [];
        $stack = [];
        $buffer = [];
        $pendingKey = null;
        $obsoletePrefix = $this->writer->obsoleteCommentPrefix();

        foreach ($lines as $line) {
            if (! is_string($line)) {
                continue;
            }

            if ($pendingKey !== null) {
                $pendingKey .= "\n".$line;
                $parsed = $this->parseKeyDefinition($pendingKey);

                if ($parsed !== null) {
                    $fullKey = $this->buildKey($stack, $parsed['key']);

                    if ($buffer !== []) {
                        $comments[$fullKey] = $buffer;
                        $buffer = [];
                    }

                    if ($parsed['opensArray']) {
                        $stack[] = $parsed['key'];
                    }

                    $pendingKey = null;
                }

                continue;
            }

            $trimmed = trim($line);

            if ($trimmed === '') {
                $buffer = [];

                continue;
            }

            if (preg_match('/^\s*\/\/\s*(.+)$/', $line, $matches) === 1) {
                $payload = trim($matches[1]);

                if ($payload === '') {
                    continue;
                }

                if ($this->shouldIgnoreHeaderComment($payload)) {
                    continue;
                }

                if (str_starts_with($payload, $obsoletePrefix)) {
                    continue;
                }

                $buffer[] = $payload;

                continue;
            }

            if (preg_match('/^\s*]\s*,?\s*;?\s*$/', $line) === 1) {
                if ($stack !== []) {
                    array_pop($stack);
                }

                $buffer = [];

                continue;
            }

            $parsed = $this->parseKeyDefinition($line);

            if ($parsed === null) {
                if (preg_match('/^\s*\'/', $line) === 1) {
                    $pendingKey = $line;

                    continue;
                }

                $buffer = [];

                continue;
            }

            $fullKey = $this->buildKey($stack, $parsed['key']);

            if ($buffer !== []) {
                $comments[$fullKey] = $buffer;
                $buffer = [];
            }

            if ($parsed['opensArray']) {
                $stack[] = $parsed['key'];
            }
        }

        return $comments;
    }

    private function parseObsoleteCommentKey(string $payload): ?string
    {
        if (preg_match('/^(\'(?:\\\\\'|[^\'])*\'|"(?:\\\\.|[^"\\\\])*")\s*=>/', $payload, $matches) !== 1) {
            return null;
        }

        if (str_starts_with($matches[1], '"')) {
            $decoded = json_decode($matches[1], true);

            return is_string($decoded) ? $decoded : null;
        }

        return $this->unescapeExportedString($matches[1]);
    }

    private function unescapeExportedString(string $exported): string
    {
        $value = trim($exported);

        if (str_starts_with($value, '\'') && str_ends_with($value, '\'')) {
            $value = substr($value, 1, -1);
        }

        return str_replace(['\\\\', "\\'"], ['\\', "'"], $value);
    }

    /**
     * @return array{key: string, opensArray: bool}|null
     */
    private function parseKeyDefinition(string $line): ?array
    {
        if (preg_match('/^\s*(\'(?:\\\\\'|[^\'])*\')\s*=>/s', $line, $matches) !== 1) {
            return null;
        }

        $key = $this->unescapeExportedString($matches[1]);
        $opensArray = preg_match('/=>\s*\[\s*$/', $line) === 1;

        return [
            'key' => $key,
            'opensArray' => $opensArray,
        ];
    }

    private function buildKey(array $stack, string $key): string
    {
        if ($stack === []) {
            return $key;
        }

        return implode('.', array_merge($stack, [$key]));
    }

    private function shouldIgnoreHeaderComment(string $payload): bool
    {
        $header = array_map(
            static fn (string $line): string => ltrim(trim($line), '/ '),
            $this->writer->headerLines()
        );

        return in_array($payload, $header, true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveJson(string $locale, array $data, ?string $namespace = null): void
    {
        $path = $this->jsonPath($locale, $namespace);
        $this->ensureDirectory($path);

        $contents = $this->writer->toJson($data);

        $this->validator()->validateContents($contents, $path);
        File::put($path, $contents);
    }

    public function groupPath(string $locale, string $group): string
    {
        $parts = $this->splitNamespacedGroup($group);

        if ($parts['namespace'] !== null) {
            return $this->langPath().DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.$parts['namespace']
                .DIRECTORY_SEPARATOR.$locale.DIRECTORY_SEPARATOR.$parts['group'].'.php';
        }

        return $this->langPath().DIRECTORY_SEPARATOR.$locale.DIRECTORY_SEPARATOR.$parts['group'].'.php';
    }

    public function jsonPath(string $locale, ?string $namespace = null): string
    {
        if ($namespace !== null) {
            return $this->langPath().DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.$namespace
                .DIRECTORY_SEPARATOR.$locale.'.json';
        }

        return $this->langPath().DIRECTORY_SEPARATOR.$locale.'.json';
    }

    public function langPath(): string
    {
        return rtrim($this->path ?? config('vox.paths.lang', lang_path()), DIRECTORY_SEPARATOR);
    }

    public function forPath(string $path): self
    {
        return new self($this->writer, $this->validator(), $path);
    }

    /**
     * @return array{namespace: string|null, group: string}
     */
    private function splitNamespacedGroup(string $group): array
    {
        if (! str_contains($group, '::')) {
            return [
                'namespace' => null,
                'group' => $group,
            ];
        }

        [$namespace, $groupName] = explode('::', $group, 2);

        return [
            'namespace' => $namespace !== '' ? $namespace : null,
            'group' => $groupName !== '' ? $groupName : $group,
        ];
    }

    private function ensureDirectory(string $path): void
    {
        $directory = dirname($path);

        if (! File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }
    }

    private function validator(): TranslationFileValidator
    {
        return $this->validator ??= new TranslationFileValidator;
    }
}
