<?php

namespace KeypointSolutions\LaravelVox\Translation\Drivers;

use Carbon\CarbonInterface;
use Throwable;

/**
 * Groups texts into batches and translates each batch in one AI request when the driver supports it, falling back to
 * one request per text otherwise.
 */
class TranslationBatcher
{
    public const MAX_ITEMS = 25;

    /**
     * Source characters per batch, keeping the reply well inside the provider's output limit and timeout.
     */
    public const MAX_CHARACTERS = 6000;

    /**
     * Execution time granted before each AI call: the provider timeout plus room to save the results.
     */
    private const AI_CALL_TIME_LIMIT_SECONDS = 90;

    /**
     * Split items into batches small enough for one request, keeping their keys and order.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, array{text: string, target: string, context?: array<string, mixed>}>  $items
     * @return list<array<TKey, array{text: string, target: string, context?: array<string, mixed>}>>
     */
    public function chunk(array $items): array
    {
        $batches = [];
        $batch = [];
        $characters = 0;

        foreach ($items as $key => $item) {
            $length = mb_strlen($item['text']);

            if ($batch !== [] && (count($batch) === self::MAX_ITEMS || $characters + $length > self::MAX_CHARACTERS)) {
                $batches[] = $batch;
                $batch = [];
                $characters = 0;
            }

            $batch[$key] = $item;
            $characters += $length;
        }

        if ($batch !== []) {
            $batches[] = $batch;
        }

        return $batches;
    }

    /**
     * Translate one batch. Results are keyed like the items: a failed item holds its exception, and items after a
     * failure or not reached before the deadline are left out, so callers keep what finished and report the failure.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, array{text: string, target: string, context?: array<string, mixed>}>  $items
     * @param  CarbonInterface|null  $deadline  Only consulted between single requests, after the first one.
     * @return array<TKey, string|Throwable>
     */
    public function translate(TranslationDriver $driver, array $items, string $sourceLocale, ?CarbonInterface $deadline = null): array
    {
        if ($items === []) {
            return [];
        }

        if (count($items) > 1 && $driver instanceof BatchTranslationDriver) {
            $this->extendTimeLimitForAiCall(self::AI_CALL_TIME_LIMIT_SECONDS * 2);

            try {
                return $driver->translateMany($items, $sourceLocale);
            } catch (Throwable $exception) {
                return [array_key_first($items) => $exception];
            }
        }

        $results = [];

        foreach ($items as $key => $item) {
            if ($results !== [] && $deadline !== null && now()->greaterThanOrEqualTo($deadline)) {
                break;
            }

            $this->extendTimeLimitForAiCall(self::AI_CALL_TIME_LIMIT_SECONDS);

            try {
                $results[$key] = $driver->translate($item['text'], $sourceLocale, $item['target'], $item['context'] ?? []);
            } catch (Throwable $exception) {
                $results[$key] = $exception;

                break;
            }
        }

        return $results;
    }

    /**
     * Restart PHP's execution timer so a slow provider response cannot end the request with a fatal error. Runtimes
     * without a limit, such as the CLI, are left unlimited. A batch gets room for the retries of items its reply garbled.
     */
    private function extendTimeLimitForAiCall(int $seconds): void
    {
        $limit = (int) ini_get('max_execution_time');

        if ($limit > 0 && function_exists('set_time_limit')) {
            set_time_limit(max($limit, $seconds));
        }
    }
}
