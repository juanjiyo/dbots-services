<?php

declare(strict_types=1);

namespace Dbots\CenTeRServ\Application\Port\Out;

interface RawBlocklistRepositoryInterface
{
    public function add(string $command): void;

    public function remove(string $command): bool;

    public function contains(string $command): bool;

    /**
     * @return list<string>
     */
    public function all(): array;

    public function clear(): void;
}
