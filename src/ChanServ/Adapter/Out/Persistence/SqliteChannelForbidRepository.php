<?php

declare(strict_types=1);

namespace Dbots\ChanServ\Adapter\Out\Persistence;

use Dbots\ChanServ\Application\Port\Out\ChannelForbidRepositoryInterface;
use PDO;

final readonly class SqliteChannelForbidRepository implements ChannelForbidRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS channel_forbids (
                channel TEXT PRIMARY KEY COLLATE NOCASE,
                reason TEXT NOT NULL,
                set_by TEXT NOT NULL
            )'
        );
    }

    public function add(string $channel, string $reason, string $setBy): void
    {
        $statement = $this->pdo->prepare(
            'INSERT OR REPLACE INTO channel_forbids (channel, reason, set_by) VALUES (:channel, :reason, :setBy)'
        );
        $statement->execute(['channel' => $channel, 'reason' => $reason, 'setBy' => $setBy]);
    }

    public function remove(string $channel): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM channel_forbids WHERE channel = :channel');
        $statement->execute(['channel' => $channel]);

        return $statement->rowCount() > 0;
    }

    public function find(string $channel): ?string
    {
        $statement = $this->pdo->prepare('SELECT reason FROM channel_forbids WHERE channel = :channel');
        $statement->execute(['channel' => $channel]);

        $reason = $statement->fetchColumn();

        return false === $reason || null === $reason ? null : (string) $reason;
    }

    public function all(): array
    {
        $statement = $this->pdo->query('SELECT channel, reason, set_by FROM channel_forbids ORDER BY channel');

        $forbids = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $forbids[(string) $row['channel']] = [
                'setBy' => (string) $row['set_by'],
                'reason' => (string) $row['reason'],
            ];
        }

        return $forbids;
    }
}
