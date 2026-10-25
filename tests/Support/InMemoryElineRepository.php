<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use Dbots\OperServ\Application\Port\Out\ElineRepositoryInterface;
use Dbots\OperServ\Domain\Eline;

final class InMemoryElineRepository implements ElineRepositoryInterface
{
    /** @var array<int, Eline> */
    private array $elines = [];

    private int $nextId = 1;

    public function save(Eline $eline): Eline
    {
        $id = $eline->id ?? $this->nextId++;
        $stored = new Eline(
            $id,
            $eline->mask,
            $eline->reason,
            $eline->who,
            $eline->createdAt,
            $eline->expiresAt,
        );
        $this->elines[$id] = $stored;

        return $stored;
    }

    public function findByMask(string $mask): ?Eline
    {
        foreach ($this->elines as $eline) {
            if (0 === strcasecmp($eline->mask, $mask)) {
                return $eline;
            }
        }

        return null;
    }

    public function findById(int $id): ?Eline
    {
        return $this->elines[$id] ?? null;
    }

    public function deleteByMask(string $mask): bool
    {
        foreach ($this->elines as $id => $eline) {
            if (0 === strcasecmp($eline->mask, $mask)) {
                unset($this->elines[$id]);

                return true;
            }
        }

        return false;
    }

    public function deleteById(int $id): bool
    {
        if (!isset($this->elines[$id])) {
            return false;
        }

        unset($this->elines[$id]);

        return true;
    }

    /**
     * @return list<Eline>
     */
    public function all(): array
    {
        return array_values($this->elines);
    }

    /**
     * @return list<Eline>
     */
    public function allActive(int $now): array
    {
        $active = [];

        foreach ($this->elines as $eline) {
            if (!$eline->isExpired($now)) {
                $active[] = $eline;
            }
        }

        return $active;
    }

    public function count(): int
    {
        return count($this->elines);
    }
}
