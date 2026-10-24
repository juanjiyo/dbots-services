<?php

declare(strict_types=1);

namespace Dbots\ChanServ\Adapter\Out\Persistence;

use Dbots\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use PDO;

final readonly class SqliteChannelAccessRepository implements ChannelAccessRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS channel_access (
                channel TEXT NOT NULL,
                nick TEXT NOT NULL COLLATE NOCASE,
                PRIMARY KEY (channel, nick)
            )'
        );
    }

    public function add(string $channel, string $nick): void
    {
        $statement = $this->pdo->prepare(
            'INSERT OR IGNORE INTO channel_access (channel, nick) VALUES (:channel, :nick)'
        );
        $statement->execute(['channel' => $channel, 'nick' => $nick]);
    }

    public function remove(string $channel, string $nick): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM channel_access WHERE channel = :channel AND nick = :nick');
        $statement->execute(['channel' => $channel, 'nick' => $nick]);

        return $statement->rowCount() > 0;
    }

    public function forChannel(string $channel): array
    {
        $statement = $this->pdo->prepare('SELECT nick FROM channel_access WHERE channel = :channel ORDER BY nick');
        $statement->execute(['channel' => $channel]);

        return array_map(static fn (array $row): string => (string) $row['nick'], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function removeAll(string $channel): void
    {
        $statement = $this->pdo->prepare('DELETE FROM channel_access WHERE channel = :channel');
        $statement->execute(['channel' => $channel]);
    }
}
