<?php

namespace KeypointSolutions\LaravelVox\Commands\Concerns;

use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Translation\Drivers\TranslationBatcher;
use KeypointSolutions\LaravelVox\Translation\Drivers\TranslationDriver;
use Symfony\Component\Console\Terminal;
use Throwable;

use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;

trait RendersAiTranslationOutput
{
    /**
     * Translate one batch, showing a spinner normally and each source with its translation in verbose mode.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, array{text: string, target: string, context?: array<string, mixed>}>  $items
     * @param  array<TKey, string>  $labels
     * @return array<TKey, string|Throwable>
     */
    private function translateBatchWithOutput(
        TranslationBatcher $batcher,
        TranslationDriver $driver,
        array $items,
        array $labels,
        string $sourceLocale,
        string $targetLocale,
    ): array {
        $firstLabel = $this->formatLabelForOutput((string) $labels[array_key_first($items)]);
        $displayLabel = count($items) === 1 ? $firstLabel : count($items).' values from '.$firstLabel;

        if (! $this->output->isVerbose()) {
            return spin(
                fn (): array => $batcher->translate($driver, $items, $sourceLocale),
                $this->formatSpinnerMessage($displayLabel, $targetLocale)
            );
        }

        $results = $batcher->translate($driver, $items, $sourceLocale);
        $rows = [];

        foreach ($items as $key => $item) {
            $result = $results[$key] ?? null;
            $outcome = match (true) {
                is_string($result) => $result,
                $result instanceof Throwable => 'Failed: '.$result->getMessage(),
                default => 'Not translated',
            };
            $rows[] = [$this->formatLabelForOutput((string) $labels[$key]), $item['text']."\n→ ".$outcome];
        }

        $this->output->writeln('');
        $this->output->writeln("AI translation to {$targetLocale}: {$displayLabel}");
        table(['Key', 'Source → Translation'], $this->formatPromptRows($rows));

        return $results;
    }

    private function formatSpinnerMessage(string $label, string $targetLocale): string
    {
        $message = "Translating {$label} to {$targetLocale}";
        $width = max(0, (new Terminal)->getWidth() - 4);

        return mb_strimwidth($message, 0, $width, $width >= 3 ? '...' : '', 'UTF-8');
    }

    private function formatLabelForOutput(string $label): string
    {
        $collapsed = preg_replace('/\s+/', ' ', $label);
        $collapsed = trim((string) $collapsed);

        if ($collapsed === '') {
            return $collapsed;
        }

        return Str::limit($collapsed, 120, '...');
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $rows
     * @return array<int, array<int, string>>
     */
    private function formatPromptRows(array $rows): array
    {
        $columns = getenv('COLUMNS');
        $maxWidth = (is_string($columns) && ctype_digit($columns)) ? (int) $columns : 120;
        $contentWidth = max(40, $maxWidth - 20);
        $formatted = [];

        foreach ($rows as $row) {
            [$role, $content] = $row;
            $lines = $this->wrapContent($content, $contentWidth);

            foreach ($lines as $index => $line) {
                $formatted[] = [$index === 0 ? $role : '', $line];
            }
        }

        return $formatted;
    }

    /**
     * @return array<int, string>
     */
    private function wrapContent(string $content, int $width): array
    {
        $content = str_replace("\r\n", "\n", $content);
        $parts = explode("\n", $content);
        $lines = [];

        foreach ($parts as $part) {
            if ($part === '') {
                $lines[] = '';

                continue;
            }

            $wrapped = wordwrap($part, $width, "\n", true);
            $lines = array_merge($lines, explode("\n", $wrapped));
        }

        return $lines === [] ? [''] : $lines;
    }
}
