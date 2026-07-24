<?php

namespace KeypointSolutions\LaravelVox\Translation;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use KeypointSolutions\LaravelVox\Support\VoxSettingsRepository;

class TranslationPromptBuilder
{
    public function __construct(private VoxSettingsRepository $settings) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function build(string $text, string $sourceLocale, string $targetLocale, array $context = []): string
    {
        $prompt = (string) config('vox.translate.prompt');
        $doNotTranslate = Arr::get(config('vox.translate.terms', []), 'do_not_translate', []);
        $fixedTerms = Arr::get(config('vox.translate.terms', []), 'fixed', []);

        $matchedDoNotTranslate = [];

        if (is_array($doNotTranslate)) {
            foreach ($doNotTranslate as $term) {
                if (! is_string($term) || $term === '') {
                    continue;
                }

                if (Str::contains($text, $term, true)) {
                    $matchedDoNotTranslate[] = $term;
                }
            }
        }

        $fixedLines = [];

        foreach ($fixedTerms as $term => $translations) {
            if (is_array($translations) && isset($translations[$targetLocale])) {
                $fixedLines[] = $term.' => '.$translations[$targetLocale];
            }
        }

        $extra = [];

        if ($matchedDoNotTranslate !== []) {
            $extra[] = 'Do not translate: '.implode(', ', $matchedDoNotTranslate);
        }

        if ($fixedLines !== []) {
            $extra[] = 'Fixed translations: '.implode('; ', $fixedLines);
        }

        $contextLine = $this->contextLine($context);

        if ($contextLine !== null) {
            $extra[] = 'The user message is the only text to translate. Do not translate, copy, or include any context.';
            $extra[] = 'Do not introduce HTML, markdown, or other markup that does not appear in the user message.';
            $extra[] = 'BEGIN_CONTEXT';
            $extra[] = $contextLine;
            $extra[] = 'END_CONTEXT';
        }

        $guidance = trim((string) $this->settings->get(
            'translate_guidance',
            config('vox.translate.guidance', '')
        ));

        if ($guidance !== '') {
            $extra[] = 'Apply the following additional guidance only when it does not conflict with the requirements above.';
            $extra[] = 'BEGIN_ADDITIONAL_GUIDANCE';
            $extra[] = $guidance;
            $extra[] = 'END_ADDITIONAL_GUIDANCE';
        }

        $compiled = str_replace(
            [':source', ':target', ':text'],
            [$sourceLocale, $targetLocale, $text],
            $prompt
        );

        if ($extra !== []) {
            $compiled .= "\n".implode("\n", $extra);
        }

        return $compiled;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function contextLine(array $context): ?string
    {
        if (! config('vox.translate.use_context', true)) {
            return null;
        }

        $value = $context['context'] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return $value;
    }
}
