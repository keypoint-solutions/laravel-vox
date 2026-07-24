<?php

namespace KeypointSolutions\LaravelVox\Translation;

class TranslationKey
{
    public function __construct(
        public string $key,
        public ?string $group,
        public string $raw
    ) {}

    public static function fromRaw(string $raw): self
    {
        if (preg_match('/^([^\s.]+)\.(.+)$/s', $raw, $matches) === 1) {
            return new self($matches[2], $matches[1], $raw);
        }

        return new self($raw, null, $raw);
    }

    public function fullKey(): string
    {
        if ($this->group === null) {
            return $this->key;
        }

        return $this->group.'.'.$this->key;
    }
}
