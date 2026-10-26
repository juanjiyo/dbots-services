<?php

declare(strict_types=1);

namespace Dbots\Module;

use Closure;
use Dbots\Application\Port\Out\LinkTransportInterface;
use Dbots\Irc\Adapter\Out\Irc\ServiceUidFormatter;
use Dbots\OperServ\Application\Port\Out\EnforcementInterface;
use PDO;
use RuntimeException;
use Throwable;

use function array_key_exists;
use function array_keys;
use function array_values;
use function fwrite;
use function is_dir;
use function mkdir;
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
    private const RESERVATION_REASON = 'Reservado para Servicios';

    /** @var array<string, array{module: ModuleInterface, uid: string}> */
    private array $loaded = [];

    /** @var array<string, PDO> */
    private array $pdos = [];

    /** @var list<array{due: int, callback: Closure}> */
    private array $scheduled = [];

    private int $nextIndex = 0;

    public function __construct(
        private ModuleRegistry $registry,
        private LinkTransportInterface $transport,
        private ServiceUidFormatter $uidFormatter,
        private EnforcementInterface $enforcement,
        private string $sid,
        private string $serviceHost,
        private string $debugChannel,
        private string $dataDirectory = '',
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
        foreach ($this->loaded as $name => $entry) {
            if ($entry['module'] === $module) {
                return $this->context($name, $entry);
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

    /** Triggers scheduled callbacks whose time has come. */
    public function tick(): void
    {
        $now = time();
        $due = [];
        $remaining = [];

        foreach ($this->scheduled as $item) {
            if ($item['due'] <= $now) {
                $due[] = $item['callback'];

                continue;
            }

            $remaining[] = $item;
        }

        $this->scheduled = $remaining;

        foreach ($due as $callback) {
            try {
                $callback();
            } catch (Throwable $error) {
                fwrite(STDERR, sprintf("scheduled callback failed: %s\n", $error->getMessage()));
            }
        }
    }

    /** Returns the SQLite PDO for a module, creating the file if needed. */
    public function pdoFor(string $name): PDO
    {
        if (isset($this->pdos[$name])) {
            return $this->pdos[$name];
        }

        $path = '' !== $this->dataDirectory
            ? sprintf('%s/modules/%s.db', $this->dataDirectory, $name)
            : sprintf('data/modules/%s.db', $name);

        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0o750, true);
        }

        $pdo = new PDO(sprintf('sqlite:%s', $path));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdos[$name] = $pdo;

        return $pdo;
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
                $module->onLoad($this->context($name, $entry));
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
                $module->onUnload($this->context($name, $entry));
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

        $this->enforcement->reserveNick($entry['module']->nick(), self::RESERVATION_REASON);
    }

    /** @param array{module: ModuleInterface, uid: string} $entry */
    private function joinModule(array $entry): void
    {
        $this->transport->writeLine(sprintf(':%s JOIN %s', $entry['uid'], $this->debugChannel));
        $this->transport->writeLine(sprintf(':%s SAMODE %s +o %s', $this->sid, $this->debugChannel, $entry['uid']));
    }

    /** @param array{module: ModuleInterface, uid: string} $entry */
    private function context(string $name, array $entry): ModuleContext
    {
        return new ModuleContext(
            $entry['uid'],
            $this->sid,
            $this->transport,
            $this->enforcement,
            $this->pdoFor($name),
            function (int $seconds, Closure $callback): void {
                $this->scheduled[] = [
                    'due' => time() + $seconds,
                    'callback' => $callback,
                ];
            },
        );
    }
}
