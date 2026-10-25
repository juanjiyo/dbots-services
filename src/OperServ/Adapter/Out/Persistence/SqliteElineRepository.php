<?php

declare(strict_types=1);

namespace Dbots\OperServ\Adapter\Out\Persistence;

use Dbots\OperServ\Application\Port\Out\ElineRepositoryInterface;
use Dbots\OperServ\Domain\Eline;
use PDO;

final readonly class SqliteElineRepository implements ElineRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS elines (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                mask TEXT UNIQUE NOT NULL,
                reason TEXT NOT NULL,
                who TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                expires_at INTEGER NOT NULL
            )'
        );
    }

    public function save(Eline $eline): Eline
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO elines (mask, reason, who, created_at, expires_at)
             VALUES (:mask, :reason, :who, :created_at, :expires_at)'
        );

        $statement->execute([
            'mask' => strtolower($eline->mask),
            'reason' => $eline->reason,
            'who' => $eline->who,
            'created_at' => $eline->createdAt,
            'expires_at' => $eline->expiresAt,
        ]);

        $id = (int) $this->pdo->lastInsertId();

        return new Eline(
            $id,
            $eline->mask,
            $eline->reason,
            $eline->who,
            $eline->createdAt,
            $eline->expiresAt,
        );
    }

    public function findByMask(string $mask): ?Eline
    {
        $statement = $this->pdo->prepare('SELECT * FROM elines WHERE mask = :mask');
        $statement->execute(['mask' => strtolower($mask)]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return false === $row ? null : $this->hydrate($row);
    }

    public function findById(int $id): ?Eline
    {
        $statement = $this->pdo->prepare('SELECT * FROM elines WHERE id = :id');
        $statement->execute(['id' => $id]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return false === $row ? null : $this->hydrate($row);
    }

    public function deleteByMask(string $mask): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM elines WHERE mask = :mask');
        $statement->execute(['mask' => strtolower($mask)]);

        return $statement->rowCount() > 0;
    }

    public function deleteById(int $id): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM elines WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->rowCount() > 0;
    }

    /**
     * @return list<Eline>
     */
    public function all(): array
    {
        $elines = [];

        foreach ($this->pdo->query('SELECT * FROM elines ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $elines[] = $this->hydrate($row);
        }

        return $elines;
    }

    /**
     * @return list<Eline>
     */
    public function allActive(int $now): array
    {
        $active = [];

        foreach ($this->all() as $eline) {
            if (!$eline->isExpired($now)) {
                $active[] = $eline;
            }
        }

        return $active;
    }

    public function count(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM elines');

        return (int) $statement->fetchColumn();
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Eline
    {
        return new Eline(
            (int) $row['id'],
            (string) $row['mask'],
            (string) $row['reason'],
            (string) $row['who'],
            (int) $row['created_at'],
            (int) $row['expires_at'],
        );
    }
}
