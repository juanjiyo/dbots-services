<?php

declare(strict_types=1);

namespace Dbots\ChanServ\Adapter\Out\Persistence;

use Dbots\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use PDO;

use function array_column;
use function array_keys;
use function in_array;

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

        // Older tables only had the nick; entries default to level 0.
        $columns = $this->pdo->query('PRAGMA table_info(channel_access)')->fetchAll(PDO::FETCH_ASSOC);

        if (!in_array('level', array_column($columns, 'name'), true)) {
            $this->pdo->exec('ALTER TABLE channel_access ADD COLUMN level INTEGER NOT NULL DEFAULT 0');
        }
    }

    public function add(string $channel, string $nick, int $level): void
    {
        $statement = $this->pdo->prepare(
            'INSERT OR REPLACE INTO channel_access (channel, nick, level) VALUES (:channel, :nick, :level)'
        );
        $statement->execute(['channel' => $channel, 'nick' => $nick, 'level' => $level]);
    }

    public function entries(string $channel): array
    {
        $statement = $this->pdo->prepare(
            'SELECT nick, level FROM channel_access WHERE channel = :channel ORDER BY level DESC, nick'
        );
        $statement->execute(['channel' => $channel]);

        $entries = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $entries[(string) $row['nick']] = (int) $row['level'];
        }

        return $entries;
    }

    public function levelOf(string $channel, string $nick): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT level FROM channel_access WHERE channel = :channel AND nick = :nick'
        );
        $statement->execute(['channel' => $channel, 'nick' => $nick]);

        $level = $statement->fetchColumn();

        return false === $level || null === $level ? null : (int) $level;
    }

    public function remove(string $channel, string $nick): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM channel_access WHERE channel = :channel AND nick = :nick');
        $statement->execute(['channel' => $channel, 'nick' => $nick]);

        return $statement->rowCount() > 0;
    }

    public function forChannel(string $channel): array
    {
        return array_keys($this->entries($channel));
    }

    public function removeAll(string $channel): void
    {
        $statement = $this->pdo->prepare('DELETE FROM channel_access WHERE channel = :channel');
        $statement->execute(['channel' => $channel]);
    }
}
