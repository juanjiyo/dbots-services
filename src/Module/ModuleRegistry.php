<?php

declare(strict_types=1);

namespace Dbots\Module;

use RuntimeException;

use function class_exists;
use function file_get_contents;
use function glob;
use function is_array;
use function json_decode;
use function sprintf;

/**
 * Finds modules on disk and decides which ones are live.
 *
 * A module is trusted code running inside this process: the daemon does not
 * sandbox it. Only Root-level staff enable one.
 */
final readonly class ModuleRegistry
{
    public function __construct(
        private ModuleStateRepositoryInterface $state,
        private string $directory,
    ) {
    }

    /** @return array<string, ModuleManifest> name => manifest, sorted by name */
    public function manifests(): array
    {
        $manifests = [];

        foreach (glob($this->directory.'/*/module.json') ?: [] as $file) {
            $name = basename(dirname($file));
            $raw = file_get_contents($file);

            if (false === $raw) {
                continue;
            }

            $data = json_decode($raw, true);

            if (!is_array($data)) {
                continue;
            }

            $manifest = ModuleManifest::fromJson($name, $data);

            if (null === $manifest) {
                continue;
            }

            $manifests[$name] = $manifest;
        }

        ksort($manifests);

        return $manifests;
    }

    /** @return list<ModuleInterface> */
    public function enabled(): array
    {
        $modules = [];

        foreach ($this->manifests() as $name => $manifest) {
            if (!$this->state->isEnabled($name, $manifest->enabledByDefault)) {
                continue;
            }

            $modules[] = $this->instantiate($manifest);
        }

        return $modules;
    }

    public function create(string $name): ModuleInterface
    {
        $manifests = $this->manifests();

        if (!isset($manifests[$name])) {
            throw new RuntimeException(sprintf('Module %s does not exist.', $name));
        }

        return $this->instantiate($manifests[$name]);
    }

    private function instantiate(ModuleManifest $manifest): ModuleInterface
    {
        if (!class_exists($manifest->class)) {
            throw new RuntimeException(sprintf('Module class %s does not exist.', $manifest->class));
        }

        $module = new ($manifest->class)();

        if (!$module instanceof ModuleInterface) {
            throw new RuntimeException(sprintf('%s does not implement ModuleInterface.', $manifest->class));
        }

        return $module;
    }

    public function state(): ModuleStateRepositoryInterface
    {
        return $this->state;
    }
}
