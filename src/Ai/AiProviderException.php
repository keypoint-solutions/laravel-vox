<?php

namespace KeypointSolutions\LaravelVox\Ai;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * An AI provider refused or failed a request, for example because the account is out of credits.
 */
class AiProviderException extends RuntimeException
{
    public static function fromResponse(string $provider, Response $response): self
    {
        $detail = $response->json('error.message');

        if (! is_string($detail) || trim($detail) === '') {
            $detail = trim(mb_substr($response->body(), 0, 300));
        }

        return new self(trim("{$provider} request failed (HTTP {$response->status()}): {$detail}", ' :'));
    }
}
