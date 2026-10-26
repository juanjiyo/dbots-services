<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use Dbots\CenTeRServ\Application\Port\Out\RawBlocklistRepositoryInterface;

final class InMemoryRawBlocklistRepository implements RawBlocklistRepositoryInterface
{
    /** @var array<string, true> */
    private array $commands = [];

    public function __construct()
    {
        foreach ($this->defaultCommands() as $command) {
            $this->add($command);
        }
    }

    /**
     * @return list<string>
     */
    private function defaultCommands(): array
    {
        return [
            'SQUIT',
            'CONNECT',
            'KILL',
            'GLINE',
            'UNGLINE',
            'KLINE',
            'UNKLINE',
            'RESTART',
            'DIE',
            'OPER',
            'LOCOP',
            'SDIE',
        ];
    }

    public function add(string $command): void
    {
        $this->commands[$this->normalize($command)] = true;
    }

    public function remove(string $command): bool
    {
        $normalized = $this->normalize($command);

        if (!isset($this->commands[$normalized])) {
            return false;
        }

        unset($this->commands[$normalized]);

        return true;
    }

    public function contains(string $command): bool
    {
        return isset($this->commands[$this->normalize($command)]);
    }

    public function all(): array
    {
        $commands = array_keys($this->commands);
        sort($commands);

        return $commands;
    }

    public function clear(): void
    {
        $this->commands = [];
    }

    private function normalize(string $command): string
    {
        return strtoupper(trim($command));
    }
}
