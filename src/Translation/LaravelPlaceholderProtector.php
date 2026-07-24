<?php

namespace KeypointSolutions\LaravelVox\Translation;

use RuntimeException;

class LaravelPlaceholderProtector
{
    private const PLACEHOLDER_EXPRESSION = '(?<![:\p{L}\p{N}_]):[A-Za-z_][A-Za-z0-9_]*';

    private const PLACEHOLDER_PATTERN = '/'.self::PLACEHOLDER_EXPRESSION.'/u';

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    public function protect(string $text): array
    {
        $placeholders = [];

        $protectedText = preg_replace_callback(
            self::PLACEHOLDER_PATTERN,
            function (array $matches) use (&$placeholders): string {
                $token = '__LARAVEL_PLACEHOLDER_'.count($placeholders).'__';
                $placeholders[$token] = $matches[0];

                return $token;
            },
            $text
        );

        return [$protectedText ?? $text, $placeholders];
    }

    /**
     * @param  array<string, string>  $placeholders
     */
    public function restoreAndValidate(string $source, string $translation, array $placeholders): string
    {
        $restoredTranslation = strtr($translation, $placeholders);
        $sourcePlaceholders = $this->extractPlaceholders($source);
        $translationPlaceholders = $this->extractPlaceholders($restoredTranslation, $sourcePlaceholders);

        sort($sourcePlaceholders);
        sort($translationPlaceholders);

        if ($sourcePlaceholders !== $translationPlaceholders) {
            throw new RuntimeException(sprintf(
                'Laravel placeholder mismatch. Expected [%s], found [%s].',
                implode(', ', $sourcePlaceholders),
                implode(', ', $translationPlaceholders),
            ));
        }

        return $restoredTranslation;
    }

    /**
     * @param  list<string>  $knownPlaceholders
     * @return list<string>
     */
    private function extractPlaceholders(string $text, array $knownPlaceholders = []): array
    {
        $expressions = [self::PLACEHOLDER_EXPRESSION];

        if ($knownPlaceholders !== []) {
            $knownPlaceholderNames = array_map(
                fn (string $placeholder): string => preg_quote(substr($placeholder, 1), '/'),
                array_unique($knownPlaceholders),
            );

            usort(
                $knownPlaceholderNames,
                fn (string $first, string $second): int => strlen($second) <=> strlen($first),
            );

            array_unshift(
                $expressions,
                ':(?:'.implode('|', $knownPlaceholderNames).')(?![A-Za-z0-9_])',
            );
        }

        preg_match_all('/(?:'.implode('|', $expressions).')/u', $text, $matches);

        return $matches[0];
    }
}
