<?php

declare(strict_types=1);

namespace Dbots\Staff\Adapter\Out\Persistence;

use Dbots\Domain\Staff\StaffRank;
use Dbots\Staff\Application\Port\Out\StaffRankRepositoryInterface;
use PDO;

final readonly class SqliteStaffRankRepository implements StaffRankRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS staff_ranks (
                account TEXT PRIMARY KEY COLLATE NOCASE,
                rank TEXT NOT NULL
            )'
        );
    }

    public function rankOf(string $account): ?StaffRank
    {
        $statement = $this->pdo->prepare('SELECT rank FROM staff_ranks WHERE account = :account');
        $statement->execute(['account' => $account]);

        $rank = $statement->fetchColumn();

        return false === $rank || null === $rank ? null : StaffRank::fromName((string) $rank);
    }

    public function assign(string $account, StaffRank $rank): void
    {
        $statement = $this->pdo->prepare(
            'INSERT OR REPLACE INTO staff_ranks (account, rank) VALUES (:account, :rank)'
        );
        $statement->execute(['account' => $account, 'rank' => $rank->value]);
    }

    public function revoke(string $account): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM staff_ranks WHERE account = :account');
        $statement->execute(['account' => $account]);

        return $statement->rowCount() > 0;
    }

    public function all(): array
    {
        $statement = $this->pdo->query('SELECT account, rank FROM staff_ranks ORDER BY rank DESC, account');

        $ranks = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rank = StaffRank::fromName((string) $row['rank']);

            if (null !== $rank) {
                $ranks[(string) $row['account']] = $rank;
            }
        }

        return $ranks;
    }
}
