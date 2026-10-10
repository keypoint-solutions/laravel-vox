<?php

namespace KeypointSolutions\LaravelVox\Commands\Concerns;

use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Ai\TranslationPromptBuilder;
use KeypointSolutions\LaravelVox\Translation\Drivers\OpenAiTranslationDriver;
use KeypointSolutions\LaravelVox\Translation\Drivers\TranslationDriver;
use Symfony\Component\Console\Terminal;

use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;

trait RendersAiTranslationOutput
{
    /**
     * Translate one value, showing a spinner normally and the full AI exchange in verbose mode.
     *
     * @param  array<string, mixed>  $context
     */
    private function translateWithOutput(
        TranslationDriver $driver,
        TranslationPromptBuilder $promptBuilder,
        string $text,
        string $sourceLocale,
        string $targetLocale,
        string $label,
        array $context = []
    ): string {
        $displayLabel = $this->formatLabelForOutput($label);

        if (! $this->output->isVerbose()) {
            return spin(
                fn () => $driver->translate($text, $sourceLocale, $targetLocale, $context),
                $this->formatSpinnerMessage($displayLabel, $targetLocale)
            );
        }

        $this->output->writeln('');
        $this->output->writeln("AI translation: {$displayLabel}");

        $rows = [];

        if ($driver instanceof OpenAiTranslationDriver) {
            $systemPrompt = $promptBuilder->build($text, $sourceLocale, $targetLocale, $context);
            $rows[] = ['system', $systemPrompt];
        } else {
            $rows[] = ['note', 'Translation driver does not expose AI message payloads.'];
        }

        $rows[] = ['user', $text];

        $translation = $driver->translate($text, $sourceLocale, $targetLocale, $context);

        $rows[] = ['assistant', $translation];

        table(['Role', 'Content'], $this->formatPromptRows($rows));

        return $translation;
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
