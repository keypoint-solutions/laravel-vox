<?php

namespace KeypointSolutions\LaravelVox\Translation\Locales;

use Closure;
use KeypointSolutions\LaravelVox\Support\VoxListConfiguration;
use UnexpectedValueException;

/**
 * The locale list an application declares through `vox.translate.locales`: configured values, a registered
 * callback, or nothing in auto mode, where locales are discovered from published files instead.
 */
class VoxLocaleDeclaration
{
    public const MODE_CALLBACK = 'callback';

    private ?Closure $callback = null;

    public function __construct(private VoxListConfiguration $listConfiguration) {}

    /**
     * @param  callable(): iterable<int, mixed>  $callback
     */
    public function using(callable $callback): void
    {
        $this->callback = Closure::fromCallable($callback);
    }

    public function hasCallback(): bool
    {
        return $this->callback !== null;
    }

    /**
     * The mode set in configuration, before falling back to auto for a missing callback.
     */
    public function configuredMode(): string
    {
        return config('vox.translate.locales.mode') === self::MODE_CALLBACK
            ? self::MODE_CALLBACK
            : $this->listConfiguration->mode('vox.translate.locales');
    }

    /**
     * The mode in effect: callback mode without a registered callback behaves like auto.
     */
    public function mode(): string
    {
        $mode = $this->configuredMode();

        return $mode === self::MODE_CALLBACK && $this->callback === null ? VoxListConfiguration::MODE_AUTO : $mode;
    }

    /**
     * Where the declared locale list comes from, for messages shown to developers.
     */
    public function sourceLabel(): string
    {
        return $this->mode() === self::MODE_CALLBACK ? 'the locale callback' : 'vox.translate.locales';
    }

    public function isDeclarative(): bool
    {
        return $this->mode() !== VoxListConfiguration::MODE_AUTO;
    }

    /**
     * @return array<int, string>|null Null in auto mode.
     */
    public function locales(): ?array
    {
        return match ($this->mode()) {
            VoxListConfiguration::MODE_CONFIGURED => $this->listConfiguration->configuredValues('vox.translate.locales'),
            self::MODE_CALLBACK => $this->callbackLocales(),
            default => null,
        };
    }

    /**
     * @return array<int, string>
     */
    private function callbackLocales(): array
    {
        $locales = ($this->callback)();

        if (! is_iterable($locales)) {
            throw new UnexpectedValueException('The Vox locale callback must return an iterable of locale codes, '.get_debug_type($locales).' returned.');
        }

        $values = [];

        foreach ($locales as $locale) {
            if (! is_string($locale)) {
                throw new UnexpectedValueException('The Vox locale callback must return locale codes as strings, '.get_debug_type($locale).' returned.');
            }

            $values[] = trim($locale);
        }

        return array_values(array_unique(array_filter($values, static fn (string $locale): bool => $locale !== '')));
    }
}
