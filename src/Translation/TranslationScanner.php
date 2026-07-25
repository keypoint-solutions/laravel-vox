<?php

namespace KeypointSolutions\LaravelVox\Translation;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class TranslationScanner
{
    private const BACKEND_TRANSLATION_CALL_PATTERN = 'trans_choice|trans\(\s*\)->(?:choice|get|string)|trans|__|@choice|@lang|(?:Lang|Translator)::(?:choice|get|string)|app\(\s*[\'"]translator[\'"]\s*\)->(?:choice|get|string)';

    private const BACKEND_ARRAY_TRANSLATION_CALL_PATTERN = 'trans\(\s*\)->array|(?:Lang|Translator)::array|app\(\s*[\'"]translator[\'"]\s*\)->array';

    private const FRONTEND_TRANSLATION_CALL_PATTERN = '\$tChoice|\$wtChoice|transChoice|trans_choice|wTransChoice|\$t|\$wt|trans|wTrans|__';

    private const PHP_STRING_LITERAL_PATTERN = '(?:\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*")';

    /**
     * @var array<string, array{prefix: string, suffix: string, source: string|null, is_frontend: bool, file: string, line: int|null, context: string|null}>
     */
    private array $dynamicKeys = [];

    /**
     * @var array<int, string>
     */
    private array $lastFiles = [];

    private bool $filesLoaded = false;

    /**
     * @param  array<int, string>  $paths
     * @param  array<int, string>  $exclude
     * @param  array<int, string>  $extensions
     */
    public function __construct(
        private string $basePath,
        private array $paths,
        private array $exclude,
        private array $extensions,
        private int $contextLines
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function scan(): array
    {
        $files = $this->gatherFiles();
        $this->lastFiles = $files;
        $this->filesLoaded = true;
        $maxOccurrences = max(0, (int) config('vox.parse.max_occurrences', 5));
        $results = [];

        foreach ($files as $filePath) {
            $content = File::get($filePath);
            $extension = $this->resolveExtension($filePath);
            $matches = $this->matchKeys($content, $extension);
            $dynamicMatches = $this->matchDynamicKeys($content, $extension);

            $this->recordDynamicMatches($dynamicMatches, $filePath, $content);

            if ($matches === []) {
                continue;
            }

            $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];

            foreach ($matches as $match) {
                $translationKey = TranslationKey::fromRaw($match['key']);
                $fullKey = $translationKey->fullKey();

                if (! isset($results[$fullKey])) {
                    $results[$fullKey] = [
                        'key' => $translationKey->key,
                        'group' => $translationKey->group,
                        'raw' => $translationKey->raw,
                        'is_frontend' => $match['is_frontend'],
                        'source' => $match['source'],
                        'occurrences' => [],
                    ];
                }

                $results[$fullKey]['is_frontend'] = $results[$fullKey]['is_frontend'] || $match['is_frontend'];

                if ($results[$fullKey]['source'] === null) {
                    $results[$fullKey]['source'] = $match['source'];
                }

                if (count($results[$fullKey]['occurrences']) < $maxOccurrences) {
                    $context = $this->extractContext(
                        $content,
                        $lines,
                        $match['offset'],
                        $match['length'] ?? strlen($match['key'])
                    );
                    $results[$fullKey]['occurrences'][] = [
                        'file' => $this->relativePath($filePath),
                        'line' => $context['line'],
                        'before' => $context['before'],
                        'after' => $context['after'],
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * @return array<int, string>
     */
    private function gatherFiles(): array
    {
        $paths = [];

        foreach ($this->paths as $path) {
            $paths[] = $this->normalizePath($path);
        }

        $files = [];

        foreach ($paths as $path) {
            if (! File::exists($path)) {
                continue;
            }

            if (File::isFile($path)) {
                $files[] = $path;

                continue;
            }

            foreach (File::allFiles($path) as $file) {
                $files[] = $file->getPathname();
            }
        }

        return array_values(array_filter($files, function (string $filePath): bool {
            if (! $this->matchesExtension($filePath)) {
                return false;
            }

            return ! $this->isExcluded($filePath);
        }));
    }

    private function normalizePath(string $path): string
    {
        $trimmed = ltrim($path, DIRECTORY_SEPARATOR);

        if (Str::startsWith($path, DIRECTORY_SEPARATOR) && File::exists($path)) {
            return $path;
        }

        return Str::startsWith($path, DIRECTORY_SEPARATOR)
            ? $this->basePath.$path
            : $this->basePath.DIRECTORY_SEPARATOR.$trimmed;
    }

    private function matchesExtension(string $filePath): bool
    {
        foreach ($this->extensions as $extension) {
            if (Str::endsWith($filePath, '.'.$extension)) {
                return true;
            }

            if ($extension === 'blade.php' && Str::endsWith($filePath, '.blade.php')) {
                return true;
            }
        }

        return false;
    }

    private function isExcluded(string $filePath): bool
    {
        $relativePath = $this->relativePath($filePath);

        foreach ($this->exclude as $pattern) {
            if ($pattern === '') {
                continue;
            }

            if ($this->isRegex($pattern) && preg_match($pattern, $relativePath) === 1) {
                return true;
            }

            $normalized = ltrim($pattern, DIRECTORY_SEPARATOR);

            if (Str::is($normalized, $relativePath)) {
                return true;
            }
        }

        return false;
    }

    private function relativePath(string $filePath): string
    {
        return ltrim(Str::replaceFirst($this->basePath, '', $filePath), DIRECTORY_SEPARATOR);
    }

    private function isRegex(string $pattern): bool
    {
        return Str::startsWith($pattern, '/') && Str::endsWith($pattern, '/') && strlen($pattern) > 2;
    }

    private function resolveExtension(string $filePath): string
    {
        if (Str::endsWith($filePath, '.blade.php')) {
            return 'blade.php';
        }

        return pathinfo($filePath, PATHINFO_EXTENSION) ?: '';
    }

    /**
     * @return array<int, array{key: string, offset: int, length: int, source: string, is_frontend: bool}>
     */
    private function matchKeys(string $content, string $extension): array
    {
        $patterns = [];
        $matches = [];

        if (in_array($extension, ['php', 'blade.php'], true)) {
            $matches = $this->matchConcatenatedBackendKeys($content);
            $patterns[] = [
                'pattern' => '/(?<!\w)('.self::BACKEND_TRANSLATION_CALL_PATTERN.')\(\s*([\'"])(?<key>(?:\\\\.|(?!\2).)*)\2/s',
                'is_frontend' => false,
                'php_literal' => true,
            ];
        }

        if (in_array($extension, ['js', 'ts', 'vue'], true)) {
            $patterns[] = [
                'pattern' => '/(?<!\w)('.self::FRONTEND_TRANSLATION_CALL_PATTERN.')\(\s*(`)(?![^`]*\$\{)\s*(.*?)\s*\2/s',
                'is_frontend' => true,
            ];
            $patterns[] = [
                'pattern' => '/(?<!\w)('.self::FRONTEND_TRANSLATION_CALL_PATTERN.')\(\s*([\'"])(?<key>(?:\\\\.|(?!\2).)*)\2/s',
                'is_frontend' => true,
                'javascript_literal' => true,
            ];
        }

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern['pattern'], $content, $results, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }

            foreach ($results[3] as $index => $capture) {
                $rawKey = $capture[0];
                $key = trim($rawKey);
                $quote = $results[2][$index][0] ?? null;

                if (($pattern['php_literal'] ?? false) && is_string($quote)) {
                    $key = $this->decodePhpStringLiteral($key, $quote);
                }

                if (($pattern['javascript_literal'] ?? false) && is_string($quote)) {
                    $key = $this->decodeJavaScriptStringLiteral($key);
                }

                $source = $results[1][$index][0] ?? null;
                $source = is_string($source) ? $source : null;
                $fullMatch = $results[0][$index][0] ?? null;
                $fullOffset = $results[0][$index][1] ?? null;

                if ($key === '') {
                    continue;
                }

                if (
                    is_string($fullMatch)
                    && is_int($fullOffset)
                    && $this->hasDynamicContinuation($content, $fullOffset, strlen($fullMatch))
                ) {
                    continue;
                }

                $matches[] = [
                    'key' => $key,
                    'offset' => $capture[1],
                    'length' => strlen($rawKey),
                    'source' => $source,
                    'is_frontend' => $pattern['is_frontend'],
                ];
            }
        }

        return $matches;
    }

    /**
     * @return array<int, array{key: string, offset: int, length: int, source: string, is_frontend: bool}>
     */
    private function matchConcatenatedBackendKeys(string $content): array
    {
        $literal = self::PHP_STRING_LITERAL_PATTERN;
        $pattern = '/(?<!\w)('.self::BACKEND_TRANSLATION_CALL_PATTERN.')\(\s*'
            .'(?<argument>'.$literal.'(?:\s*\.\s*'.$literal.')+)'
            .'(?=\s*[,)]\s*)/s';

        if (preg_match_all($pattern, $content, $results, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $matches = [];

        foreach ($results as $result) {
            $argument = $result['argument'][0] ?? null;
            $offset = $result['argument'][1] ?? null;
            $source = $result[1][0] ?? null;

            if (! is_string($argument) || ! is_int($offset) || ! is_string($source)) {
                continue;
            }

            $key = $this->concatenatePhpStringLiterals($argument);

            if ($key === '') {
                continue;
            }

            $matches[] = [
                'key' => $key,
                'offset' => $offset,
                'length' => strlen($argument),
                'source' => $source,
                'is_frontend' => false,
            ];
        }

        return $matches;
    }

    private function concatenatePhpStringLiterals(string $argument): string
    {
        if (preg_match_all(
            '/([\'"])(?<value>(?:\\\\.|(?!\1).)*)\1/s',
            $argument,
            $literals,
            PREG_SET_ORDER
        ) === false) {
            return '';
        }

        $key = '';

        foreach ($literals as $literal) {
            $quote = $literal[1] ?? null;
            $value = $literal['value'] ?? null;

            if (! is_string($quote) || ! is_string($value)) {
                continue;
            }

            $key .= $this->decodePhpStringLiteral($value, $quote);
        }

        return $key;
    }

    private function decodePhpStringLiteral(string $value, string $quote): string
    {
        if ($quote === "'") {
            return strtr($value, [
                "\\'" => "'",
                '\\\\' => '\\',
            ]);
        }

        return strtr($value, [
            '\\\\' => '\\',
            '\\"' => '"',
            '\\$' => '$',
            '\n' => "\n",
            '\r' => "\r",
            '\t' => "\t",
            '\v' => "\v",
            '\e' => "\e",
            '\f' => "\f",
        ]);
    }

    private function decodeJavaScriptStringLiteral(string $value): string
    {
        return strtr($value, [
            '\\\\' => '\\',
            "\\'" => "'",
            '\\"' => '"',
            '\n' => "\n",
            '\r' => "\r",
            '\t' => "\t",
            '\v' => "\v",
            '\b' => "\x08",
            '\f' => "\f",
            '\0' => "\0",
        ]);
    }

    private function hasDynamicContinuation(string $content, int $offset, int $length): bool
    {
        $tail = substr($content, $offset + $length);

        if ($tail === false) {
            return false;
        }

        return preg_match('/^\s*[.+]/', $tail) === 1;
    }

    /**
     * @return array<int, array{prefix: string, suffix: string, source: string|null, is_frontend: bool, offset: int|null, match: string|null}>
     */
    private function matchDynamicKeys(string $content, string $extension): array
    {
        $patterns = [];

        if (in_array($extension, ['php', 'blade.php'], true)) {
            $patterns[] = [
                'pattern' => '/(?<!\w)('.self::BACKEND_ARRAY_TRANSLATION_CALL_PATTERN.')\(\s*([\'"])(?<prefix>(?:\\\\.|(?!\2).)*)\2(?=\s*[,)]\s*)/s',
                'is_frontend' => false,
                'subtree' => true,
            ];
            $patterns[] = [
                'pattern' => '/(?<!\w)('.self::BACKEND_TRANSLATION_CALL_PATTERN.')\(\s*([\'"])(?<prefix>(?:\\\\.|(?!\2).)*)\2\s*\.\s*[^)]*?(?:\.\s*([\'"])(?<suffix>(?:\\\\.|(?!\4).)*)\4)?\s*\)/s',
                'is_frontend' => false,
            ];
        }

        if (in_array($extension, ['js', 'ts', 'vue'], true)) {
            $patterns[] = [
                'pattern' => '/(?<!\w)('.self::FRONTEND_TRANSLATION_CALL_PATTERN.')\(\s*`(?<prefix>[^`]*?)\$\{[^}]+\}(?<suffix>[^`]*)`(?=\s*[,)]\s*)/s',
                'is_frontend' => true,
            ];
            $patterns[] = [
                'pattern' => '/(?<!\w)('.self::FRONTEND_TRANSLATION_CALL_PATTERN.')\(\s*([\'"])(?<prefix>(?:\\\\.|(?!\2).)*)\2\s*\+\s*[^)]*?(?:\+\s*([\'"])(?<suffix>(?:\\\\.|(?!\4).)*)\4)?\s*\)/s',
                'is_frontend' => true,
            ];
        }

        $matches = [];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern['pattern'], $content, $results,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
                continue;
            }

            foreach ($results as $result) {
                $prefix = trim((string) (($result['prefix'][0] ?? $result['prefix'] ?? '')));
                $suffix = (string) (($result['suffix'][0] ?? $result['suffix'] ?? ''));
                $source = $result[1][0] ?? $result[1] ?? null;
                $source = is_string($source) ? $source : null;
                $matchText = $result[0][0] ?? null;
                $offset = $result[0][1] ?? null;

                if ($prefix === '') {
                    continue;
                }

                if (
                    ! $pattern['is_frontend']
                    && is_string($matchText)
                    && $this->hasOnlyStaticPhpLiteralFirstArgument($matchText)
                ) {
                    continue;
                }

                if ($pattern['subtree'] ?? false) {
                    $prefix = rtrim($prefix, '.').'.';
                }

                $matches[] = [
                    'prefix' => $prefix,
                    'suffix' => $suffix,
                    'source' => $source,
                    'is_frontend' => $pattern['is_frontend'],
                    'offset' => is_int($offset) ? $offset : null,
                    'match' => is_string($matchText) ? $matchText : null,
                ];
            }
        }

        return $matches;
    }

    private function hasOnlyStaticPhpLiteralFirstArgument(string $call): bool
    {
        $openingParenthesis = strpos($call, '(');

        if ($openingParenthesis === false) {
            return false;
        }

        $argument = substr($call, $openingParenthesis + 1);

        if ($argument === false) {
            return false;
        }

        $literal = self::PHP_STRING_LITERAL_PATTERN;

        return preg_match(
            '/^\s*'.$literal.'(?:\s*\.\s*'.$literal.')+\s*(?:,|\))/s',
            $argument
        ) === 1;
    }

    /**
     * @param  array<int, array{prefix: string, suffix: string, source: string|null, is_frontend: bool, offset: int|null, match: string|null}>  $matches
     */
    private function recordDynamicMatches(array $matches, string $filePath, string $content): void
    {
        if ($matches === []) {
            return;
        }

        $relative = $this->relativePath($filePath);

        foreach ($matches as $match) {
            $suffixPattern = preg_replace('/\$\{[^}]+\}/s', '*', $match['suffix']) ?? $match['suffix'];
            $pattern = $match['prefix'].'*'.$suffixPattern;
            $key = $pattern.'|'.($match['is_frontend'] ? 'frontend' : 'backend');

            if (isset($this->dynamicKeys[$key])) {
                continue;
            }

            $offset = $match['offset'] ?? null;
            $line = is_int($offset) ? substr_count($content, "\n", 0, $offset) + 1 : null;
            $context = $match['match'] ?? null;
            $context = is_string($context) ? $this->normalizeContext($context) : null;

            $this->dynamicKeys[$key] = [
                'pattern' => $pattern,
                'prefix' => $match['prefix'],
                'suffix' => $match['suffix'],
                'source' => $match['source'],
                'is_frontend' => $match['is_frontend'],
                'file' => $relative,
                'line' => $line,
                'context' => $context,
            ];
        }
    }

    private function normalizeContext(string $text): string
    {
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * @param  array<int, string>  $lines
     * @return array{line: int, before: string, after: string}
     */
    private function extractContext(string $content, array $lines, int $offset, int $length): array
    {
        $startLineNumber = substr_count($content, "\n", 0, $offset) + 1;
        $startIndex = max(0, $startLineNumber - 1);
        $beforeStart = max(0, $startIndex - $this->contextLines);

        // Calculate the end position of the key and which line it ends on
        $keyEndOffset = $offset + $length;
        $keyContent = substr($content, $offset, $length);
        $keyNewlines = $keyContent !== false ? substr_count($keyContent, "\n") : 0;
        $endLineNumber = $startLineNumber + $keyNewlines;
        $endIndex = max(0, $endLineNumber - 1);

        // Get lines before the key starts
        $beforeLines = array_slice($lines, $beforeStart, $startIndex - $beforeStart);

        // Get lines after the key ends
        $afterStart = min(count($lines), $endIndex + 1);
        $afterLines = array_slice($lines, $afterStart, $this->contextLines);

        // Get the portion of the start line before the key
        $startLine = $lines[$startIndex] ?? '';
        $prefixSlice = substr($content, 0, $offset);
        $lineStart = $prefixSlice === false ? false : strrpos($prefixSlice, "\n");
        $lineStart = $lineStart === false ? -1 : $lineStart;
        $lineOffset = max(0, $offset - ($lineStart + 1));
        $lineBefore = $lineOffset > 0 ? substr($startLine, 0, $lineOffset) : '';

        // Get the portion of the end line after the key
        $endLine = $lines[$endIndex] ?? '';
        if ($keyNewlines === 0) {
            // Key is on a single line
            $endLineLength = strlen($endLine);
            $keyLengthOnLine = max(0, min($length, max(0, $endLineLength - $lineOffset)));
            $lineAfter = $endLineLength > 0 ? substr($endLine, $lineOffset + $keyLengthOnLine) : '';
        } else {
            // Key spans multiple lines - find where it ends on the last line
            $keyEndInContent = substr($content, 0, $keyEndOffset);
            $lastNewlinePos = $keyEndInContent !== false ? strrpos($keyEndInContent, "\n") : false;
            $keyEndOnLine = $lastNewlinePos === false ? $keyEndOffset : $keyEndOffset - ($lastNewlinePos + 1);
            $lineAfter = strlen($endLine) > $keyEndOnLine ? substr($endLine, $keyEndOnLine) : '';
        }

        if (is_string($lineBefore) && $lineBefore !== '') {
            $beforeLines[] = $lineBefore;
        }

        if (is_string($lineAfter) && $lineAfter !== '') {
            array_unshift($afterLines, $lineAfter);
        }
        $limit = 120;

        return [
            'line' => $startLineNumber,
            'before' => $this->limitContext($this->normalizeContext(implode(' ', $beforeLines)), $limit, true),
            'after' => $this->limitContext($this->normalizeContext(implode(' ', $afterLines)), $limit, false),
        ];
    }

    private function limitContext(string $text, int $limit, bool $fromEnd): string
    {
        if ($text === '' || strlen($text) <= $limit) {
            return $text;
        }

        if ($fromEnd) {
            $slice = substr($text, -$limit);
            $slice = $slice === false ? $text : $slice;
            $position = $this->firstBoundaryPosition($slice);

            if ($position !== null) {
                $slice = substr($slice, $position + 1) ?: $slice;
            }

            return ltrim($slice);
        }

        $slice = substr($text, 0, $limit);
        $slice = $slice === false ? $text : $slice;
        $position = $this->lastBoundaryPosition($slice);

        if ($position !== null) {
            $slice = substr($slice, 0, $position + 1) ?: $slice;
        }

        return rtrim($slice);
    }

    private function firstBoundaryPosition(string $text): ?int
    {
        if (preg_match('/[\s>);:,.\]}]/', $text, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return $match[0][1];
    }

    private function lastBoundaryPosition(string $text): ?int
    {
        if (preg_match_all('/[\s>);:,.\]}]/', $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return null;
        }

        if (! isset($matches[0]) || $matches[0] === []) {
            return null;
        }

        $last = $matches[0][count($matches[0]) - 1];

        return $last[1] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function files(): array
    {
        if (! $this->filesLoaded) {
            $this->lastFiles = $this->gatherFiles();
            $this->filesLoaded = true;
        }

        return $this->lastFiles;
    }

    /**
     * @return array<int, array{pattern: string, prefix: string, suffix: string, source: string|null, is_frontend: bool, file: string, line: int|null, context: string|null}>
     */
    public function dynamicKeys(): array
    {
        return array_values($this->dynamicKeys);
    }
}
