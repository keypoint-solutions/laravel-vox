<?php

namespace KeypointSolutions\LaravelVox\Support;

interface AiModelDiscovery
{
    /**
     * @return array{
     *     provider: array{id: string, label: string, credentials_set: bool},
     *     model: string,
     *     status: string,
     *     message: string,
     *     checked_at: string|null,
     *     models: array<int, array{value: string, label: string, available: bool}>
     * }
     */
    public function discover(bool $refresh = false): array;
}
