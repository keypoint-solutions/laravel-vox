<?php

namespace KeypointSolutions\LaravelVox\Translation;

class TranslationFileWriter
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $commented
     * @param  array<string, array<int, string>>  $lineComments
     * @param  array<int, string>  $rawCommented
     */
    public function toPhp(array $data, array $commented = [], array $lineComments = [], array $rawCommented = []): string
    {
        $sorted = $this->sortArray($data);
        $lines = $this->formatArrayLines($sorted, 0, '', $lineComments);

        if ($commented !== [] || $rawCommented !== []) {
            $commentLines = $this->formatCommentLines($commented, 1);
            $commentLines = array_merge($commentLines, $this->formatRawCommentLines($rawCommented, 1));
            $insertAt = config('vox.parse.sort_obsolete_last', true) ? count($lines) - 1 : 1;
            array_splice($lines, $insertAt, 0, $commentLines);
        }

        $header = implode("\n", $this->headerLines());

        return "<?php\n\n{$header}\n\nreturn ".implode("\n", $lines).";\n";
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function toJson(array $data): string
    {
        $sorted = $this->sortArray($data);
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;

        if (! config('vox.parse.escape_unicode', false)) {
            $flags |= JSON_UNESCAPED_UNICODE;
        }

        return json_encode($sorted, $flags) ?: '{}';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sortArray(array $data): array
    {
        if (! array_is_list($data)) {
            ksort($data);
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sortArray($value);
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function formatArrayLines(array $data, int $indent, string $prefix, array $lineComments): array
    {
        $lines = [str_repeat('    ', $indent).'['];

        foreach ($data as $key => $value) {
            $fullKey = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $keyLine = str_repeat('    ', $indent + 1).$this->exportString($key).' => ';

            if (is_array($value)) {
                $nestedLines = $this->formatArrayLines($value, $indent + 1, $fullKey, $lineComments);
                $firstNested = array_shift($nestedLines);
                $lines[] = $keyLine.ltrim((string) $firstNested);

                foreach ($nestedLines as $nestedLine) {
                    $lines[] = $nestedLine;
                }

                $lines[count($lines) - 1] .= ',';

                continue;
            }

            foreach ($this->formatInlineComments($fullKey, $indent + 1, $lineComments) as $commentLine) {
                $lines[] = $commentLine;
            }
            $lines[] = $keyLine.$this->exportValue($value).',';
        }

        $lines[] = str_repeat('    ', $indent).']';

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $commented
     * @return array<int, string>
     */
    private function formatCommentLines(array $commented, int $indent): array
    {
        $lines = [];
        $sorted = $this->sortArray($commented);

        foreach ($sorted as $key => $value) {
            $lines[] = str_repeat('    ', $indent).'// '.$this->obsoleteCommentPrefix().' '
                .$this->exportCommentValue((string) $key).' => '.$this->exportCommentValue($value).',';
        }

        return $lines;
    }

    /**
     * @param  array<int, string>  $rawCommented
     * @return array<int, string>
     */
    private function formatRawCommentLines(array $rawCommented, int $indent): array
    {
        $lines = [];

        foreach ($rawCommented as $payload) {
            if (! is_string($payload) || $payload === '') {
                continue;
            }

            $lines[] = str_repeat('    ', $indent).'// '.$this->obsoleteCommentPrefix().' '.$payload;
        }

        return $lines;
    }

    public function obsoleteCommentPrefix(): string
    {
        return '🗑️';
    }

    /**
     * @return array<int, string>
     */
    public function headerLines(): array
    {
        return [
            '// @formatter:off',
            '// phpcs:disable',
            '// phpcs:ignoreFile',
            '// phpstan-ignore-file',
            '// psalm-disable-file',
        ];
    }

    /**
     * @param  array<string, array<int, string>>  $lineComments
     * @return array<int, string>
     */
    private function formatInlineComments(string $key, int $indent, array $lineComments): array
    {
        if (! array_key_exists($key, $lineComments)) {
            return [];
        }

        $lines = [];

        foreach ($lineComments[$key] as $comment) {
            $lines[] = str_repeat('    ', $indent).'// '.$comment;
        }

        return $lines;
    }

    private function exportString(string $value): string
    {
        return var_export($value, true);
    }

    private function exportValue(mixed $value): string
    {
        if (is_string($value)) {
            return $this->exportString($value);
        }

        return var_export($value, true);
    }

    private function exportCommentValue(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }
}
