<?php

declare(strict_types=1);

namespace Dbots\OperServ\Application\Port\Out;

use Dbots\OperServ\Domain\Eline;

interface ElineRepositoryInterface
{
    /** Stores the exception and returns it with its generated id. */
    public function save(Eline $eline): Eline;

    public function findByMask(string $mask): ?Eline;

    public function findById(int $id): ?Eline;

    public function deleteByMask(string $mask): bool;

    public function deleteById(int $id): bool;

    /** @return list<Eline> */
    public function all(): array;

    /** @return list<Eline> non-expired exceptions ordered by id */
    public function allActive(int $now): array;

    public function count(): int;
}
