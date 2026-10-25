<?php

declare(strict_types=1);

namespace Dbots\ChanServ\Adapter\Out\Persistence;

use Dbots\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use Dbots\Domain\Channel\ChannelLevel;
use Dbots\Domain\Channel\ChannelName;
use PDO;

/**
 * Overrides of the level table. Anything a channel never changed falls back to
 * `ChannelLevel::default()`, so a fresh channel needs no rows at all.
 */
final readonly class SqliteChannelLevelRepository implements ChannelLevelRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS channel_levels (
                channel TEXT NOT NULL,
                level TEXT NOT NULL,
                value INTEGER NOT NULL,
                PRIMARY KEY (channel, level)
            )
            SQL);
    }

    public function levelsFor(ChannelName $channel): array
    {
        $levels = ChannelLevel::defaults();

        $statement = $this->pdo->prepare('SELECT level, value FROM channel_levels WHERE channel = :channel');
        $statement->execute(['channel' => $channel->value]);

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $levels[(string) $row['level']] = (int) $row['value'];
        }

        return $levels;
    }

    public function threshold(ChannelName $channel, ChannelLevel $level): int
    {
        $statement = $this->pdo->prepare(
            'SELECT value FROM channel_levels WHERE channel = :channel AND level = :level'
        );
        $statement->execute(['channel' => $channel->value, 'level' => $level->value]);

        $value = $statement->fetchColumn();

        return false === $value || null === $value ? $level->default() : (int) $value;
    }

    public function set(ChannelName $channel, ChannelLevel $level, int $value): void
    {
        $statement = $this->pdo->prepare(
            'INSERT OR REPLACE INTO channel_levels (channel, level, value) VALUES (:channel, :level, :value)'
        );
        $statement->execute([
            'channel' => $channel->value,
            'level' => $level->value,
            'value' => $value,
        ]);
    }

    public function reset(ChannelName $channel): void
    {
        $this->forget($channel);
    }

    public function forget(ChannelName $channel): void
    {
        $statement = $this->pdo->prepare('DELETE FROM channel_levels WHERE channel = :channel');
        $statement->execute(['channel' => $channel->value]);
    }
}
