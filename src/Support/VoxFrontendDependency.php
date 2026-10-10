<?php

namespace KeypointSolutions\LaravelVox\Support;

use Illuminate\Support\Facades\File;

class VoxFrontendDependency
{
    private const PACKAGE = 'laravel-vue-i18n';

    private const NPM_PACKAGE = '@keypoint-solutions/laravel-vox';

    /** @var array<string, list<string>> */
    private const INSTALLERS = [
        'pnpm-lock.yaml' => ['pnpm', 'add'],
        'yarn.lock' => ['yarn', 'add'],
        'bun.lock' => ['bun', 'add'],
        'bun.lockb' => ['bun', 'add'],
    ];

    public function __construct(private ?string $root = null) {}

    public function root(): string
    {
        return $this->root ?? base_path();
    }

    /**
     * Only Vue applications that have not declared the package, directly or through Vox's npm package, need it.
     */
    public function isMissing(): bool
    {
        $declared = $this->declaredPackages($this->root().'/package.json');

        return isset($declared['vue'])
            && ! isset($declared[self::PACKAGE])
            && ! isset($declared[self::NPM_PACKAGE]);
    }

    /**
     * @return list<string>
     */
    public function installCommand(): array
    {
        return [...$this->installer(), $this->requirement()];
    }

    public function installCommandLine(): string
    {
        $requirement = $this->requirement();

        return implode(' ', [
            ...$this->installer(),
            $requirement === self::PACKAGE ? $requirement : '"'.$requirement.'"',
        ]);
    }

    /**
     * @return list<string>
     */
    private function installer(): array
    {
        foreach (self::INSTALLERS as $lockfile => $installer) {
            if (File::exists($this->root().'/'.$lockfile)) {
                return $installer;
            }
        }

        return ['npm', 'install'];
    }

    private function requirement(): string
    {
        $range = $this->manifest(__DIR__.'/../../package.json')['dependencies'][self::PACKAGE] ?? null;

        return is_string($range) && $range !== '' ? self::PACKAGE.'@'.$range : self::PACKAGE;
    }

    /**
     * @return array<string, mixed>
     */
    private function declaredPackages(string $path): array
    {
        $manifest = $this->manifest($path);

        return [
            ...(is_array($manifest['dependencies'] ?? null) ? $manifest['dependencies'] : []),
            ...(is_array($manifest['devDependencies'] ?? null) ? $manifest['devDependencies'] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(string $path): array
    {
        if (! File::exists($path)) {
            return [];
        }

        $manifest = json_decode(File::get($path), true);

        return is_array($manifest) ? $manifest : [];
    }
}
