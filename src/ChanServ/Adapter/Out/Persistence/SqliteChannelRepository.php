<?php

declare(strict_types=1);

namespace Dbots\ChanServ\Adapter\Out\Persistence;

use DateTimeImmutable;
use Dbots\ChanServ\Application\Port\Out\ChannelRepositoryInterface;
use Dbots\Domain\Channel\Channel;
use Dbots\Domain\Channel\ChannelName;
use PDO;

final readonly class SqliteChannelRepository implements ChannelRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS channels (
                name TEXT PRIMARY KEY,
                founder TEXT NOT NULL,
                created_at TEXT NOT NULL
            )'
        );
    }

    public function find(ChannelName $name): ?Channel
    {
        $statement = $this->pdo->prepare('SELECT name, founder, created_at FROM channels WHERE name = :name');
        $statement->execute(['name' => $name->value]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return false === $row ? null : $this->hydrate($row);
    }

    public function save(Channel $channel): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO channels (name, founder, created_at) VALUES (:name, :founder, :created)'
        );

        $statement->execute([
            'name' => $channel->name->value,
            'founder' => $channel->founder,
            'created' => $channel->createdAt->format('c'),
        ]);
    }

    public function delete(ChannelName $name): void
    {
        $statement = $this->pdo->prepare('DELETE FROM channels WHERE name = :name');
        $statement->execute(['name' => $name->value]);
    }

    /**
     * @return list<Channel>
     */
    public function all(): array
    {
        $statement = $this->pdo->query('SELECT name, founder, created_at FROM channels ORDER BY name');

        $channels = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $channels[] = $this->hydrate($row);
        }

        return $channels;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Channel
    {
        return new Channel(
            new ChannelName((string) $row['name']),
            (string) $row['founder'],
            new DateTimeImmutable((string) $row['created_at']),
        );
    }
}
