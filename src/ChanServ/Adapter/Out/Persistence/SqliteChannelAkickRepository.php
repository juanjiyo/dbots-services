<?php

declare(strict_types=1);

namespace Dbots\ChanServ\Adapter\Out\Persistence;

use Dbots\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use Dbots\Domain\Channel\Akick;
use PDO;

final readonly class SqliteChannelAkickRepository implements ChannelAkickRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS channel_akicks (
                channel TEXT NOT NULL,
                nick TEXT NOT NULL COLLATE NOCASE,
                reason TEXT NOT NULL,
                set_by TEXT NOT NULL,
                PRIMARY KEY (channel, nick)
            )'
        );
    }

    public function add(string $channel, Akick $akick): void
    {
        $statement = $this->pdo->prepare(
            'INSERT OR REPLACE INTO channel_akicks (channel, nick, reason, set_by) VALUES (:channel, :nick, :reason, :setBy)'
        );
        $statement->execute([
            'channel' => $channel,
            'nick' => $akick->nick,
            'reason' => $akick->reason,
            'setBy' => $akick->setBy,
        ]);
    }

    public function remove(string $channel, string $nick): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM channel_akicks WHERE channel = :channel AND nick = :nick');
        $statement->execute(['channel' => $channel, 'nick' => $nick]);

        return $statement->rowCount() > 0;
    }

    public function has(string $channel, string $nick): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM channel_akicks WHERE channel = :channel AND nick = :nick');
        $statement->execute(['channel' => $channel, 'nick' => $nick]);

        return false !== $statement->fetchColumn();
    }

    public function forChannel(string $channel): array
    {
        $statement = $this->pdo->prepare('SELECT nick, reason, set_by FROM channel_akicks WHERE channel = :channel ORDER BY nick');
        $statement->execute(['channel' => $channel]);

        $akicks = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $akicks[] = new Akick((string) $row['nick'], (string) $row['reason'], (string) $row['set_by']);
        }

        return $akicks;
    }

    public function countFor(string $channel): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM channel_akicks WHERE channel = :channel');
        $statement->execute(['channel' => $channel]);

        return (int) $statement->fetchColumn();
    }

    public function removeAll(string $channel): void
    {
        $statement = $this->pdo->prepare('DELETE FROM channel_akicks WHERE channel = :channel');
        $statement->execute(['channel' => $channel]);
    }
}
