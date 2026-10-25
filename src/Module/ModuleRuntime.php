<?php

declare(strict_types=1);

namespace Dbots\Module;

use Dbots\Application\Port\Out\LinkTransportInterface;
use Dbots\Irc\Adapter\Out\Irc\ServiceUidFormatter;
use RuntimeException;
use Throwable;

use function array_key_exists;
use function array_keys;
use function array_values;
use function fwrite;
use function sprintf;

/**
 * Owns the modules that are currently live in this daemon process.
 *
 * The registry decides which modules *should* run; this runtime decides which
 * ones *do* run, introduces their pseudo-clients, and reconciles the two when
 * the enabled state changes.
 */
final class ModuleRuntime
{
    /** @var array<string, array{module: ModuleInterface, uid: string}> */
    private array $loaded = [];

    private int $nextIndex = 0;

    public function __construct(
        private ModuleRegistry $registry,
        private LinkTransportInterface $transport,
        private ServiceUidFormatter $uidFormatter,
        private string $sid,
        private string $serviceHost,
        private string $debugChannel,
    ) {
    }

    public function registry(): ModuleRegistry
    {
        return $this->registry;
    }

    /** Loads every module the registry says is enabled. Call once at startup. */
    public function loadEnabled(): void
    {
        foreach ($this->registry->manifests() as $name => $manifest) {
            if (!$this->registry->state()->isEnabled($name, $manifest->enabledByDefault)) {
                continue;
            }

            if (array_key_exists($name, $this->loaded)) {
                continue;
            }

            $this->load($name);
        }
    }

    /**
     * Reconciles the running set with the current registry state.
     *
     * Newly enabled modules are introduced; newly disabled modules are quit.
     */
    public function refresh(): void
    {
        $desired = [];

        foreach ($this->registry->manifests() as $name => $manifest) {
            if ($this->registry->state()->isEnabled($name, $manifest->enabledByDefault)) {
                $desired[$name] = true;
            }
        }

        foreach (array_keys($this->loaded) as $name) {
            if (!array_key_exists($name, $desired)) {
                $this->unload($name);
            }
        }

        foreach (array_keys($desired) as $name) {
            if (!array_key_exists($name, $this->loaded)) {
                $this->load($name);
            }
        }
    }

    /** @return list<ModuleInterface> */
    public function loaded(): array
    {
        return array_values(array_map(
            static fn (array $entry): ModuleInterface => $entry['module'],
            $this->loaded,
        ));
    }

    /** Builds a context addressed to one of the loaded modules. */
    public function contextFor(ModuleInterface $module): ModuleContext
    {
        foreach ($this->loaded as $entry) {
            if ($entry['module'] === $module) {
                return new ModuleContext($entry['uid'], $this->sid, $this->transport);
            }
        }

        throw new RuntimeException(sprintf('Module %s is not loaded.', $module->nick()));
    }

    /** Sends the UID line for every loaded module. */
    public function introduceAll(): void
    {
        foreach ($this->loaded as $entry) {
            $this->sendUid($entry);
        }
    }

    /** Joins every loaded module to the debug channel when one is configured. */
    public function joinDebugChannel(): void
    {
        if ('' === $this->debugChannel) {
            return;
        }

        foreach ($this->loaded as $entry) {
            $this->joinModule($entry);
        }
    }

    private function load(string $name): void
    {
        $module = $this->registry->create($name);
        $uid = $this->allocateUid();
        $entry = ['module' => $module, 'uid' => $uid];
        $this->loaded[$name] = $entry;

        $this->sendUid($entry);

        if ('' !== $this->debugChannel) {
            $this->joinModule($entry);
        }

        if ($module instanceof ModuleLifecycleInterface) {
            try {
                $module->onLoad($this->context($entry));
            } catch (Throwable $error) {
                fwrite(STDERR, sprintf("module %s onLoad failed: %s\n", $module->nick(), $error->getMessage()));
            }
        }
    }

    private function unload(string $name): void
    {
        $entry = $this->loaded[$name];
        unset($this->loaded[$name]);

        $module = $entry['module'];

        if ($module instanceof ModuleLifecycleInterface) {
            try {
                $module->onUnload($this->context($entry));
            } catch (Throwable $error) {
                fwrite(STDERR, sprintf("module %s onUnload failed: %s\n", $module->nick(), $error->getMessage()));
            }
        }

        $this->transport->writeLine(sprintf(':%s QUIT :Módulo descargado', $entry['uid']));
    }

    private function allocateUid(): string
    {
        // Module pseudo-clients live above the built-in service range.
        $index = $this->nextIndex++;

        return sprintf('%sA001%02d', $this->sid, $index);
    }

    /** @param array{module: ModuleInterface, uid: string} $entry */
    private function sendUid(array $entry): void
    {
        $this->transport->writeLine($this->uidFormatter->format(
            $entry['module']->nick(),
            $entry['uid'],
            $entry['module']->description(),
            $entry['module']->nick(),
            $this->serviceHost,
        ));
    }

    /** @param array{module: ModuleInterface, uid: string} $entry */
    private function joinModule(array $entry): void
    {
        $this->transport->writeLine(sprintf(':%s JOIN %s', $entry['uid'], $this->debugChannel));
        $this->transport->writeLine(sprintf(':%s SAMODE %s +o %s', $this->sid, $this->debugChannel, $entry['uid']));
    }

    /** @param array{module: ModuleInterface, uid: string} $entry */
    private function context(array $entry): ModuleContext
    {
        return new ModuleContext($entry['uid'], $this->sid, $this->transport);
    }
}
