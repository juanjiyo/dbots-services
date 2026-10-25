<?php

declare(strict_types=1);

namespace Dbots\ChanServ\Adapter\Out\Persistence;

use Dbots\ChanServ\Application\Port\Out\ChannelOptionRepositoryInterface;
use Dbots\Domain\Channel\ChannelName;
use Dbots\Domain\Channel\ChannelOption;
use PDO;

final readonly class SqliteChannelOptionRepository implements ChannelOptionRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS channel_options (
                channel TEXT NOT NULL,
                option TEXT NOT NULL,
                value TEXT NOT NULL,
                PRIMARY KEY (channel, option)
            )'
        );
    }

    public function isEnabled(ChannelName $channel, ChannelOption $option): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT value FROM channel_options WHERE channel = :channel AND option = :option'
        );
        $statement->execute(['channel' => $channel->value, 'option' => $option->value]);

        $value = $statement->fetchColumn();

        return 'on' === $value;
    }

    public function optionsFor(ChannelName $channel): array
    {
        $statement = $this->pdo->prepare('SELECT option, value FROM channel_options WHERE channel = :channel');
        $statement->execute(['channel' => $channel->value]);

        $options = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $options[(string) $row['option']] = (string) $row['value'];
        }

        return $options;
    }

    public function set(ChannelName $channel, ChannelOption $option, string $value): void
    {
        $statement = $this->pdo->prepare(
            'INSERT OR REPLACE INTO channel_options (channel, option, value) VALUES (:channel, :option, :value)'
        );
        $statement->execute(['channel' => $channel->value, 'option' => $option->value, 'value' => $value]);
    }

    public function forget(ChannelName $channel): void
    {
        $statement = $this->pdo->prepare('DELETE FROM channel_options WHERE channel = :channel');
        $statement->execute(['channel' => $channel->value]);
    }
}
