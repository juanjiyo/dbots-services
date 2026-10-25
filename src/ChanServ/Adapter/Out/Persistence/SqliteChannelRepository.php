<?php

declare(strict_types=1);

namespace Dbots\ChanServ\Adapter\Out\Persistence;

use DateTimeImmutable;
use Dbots\ChanServ\Application\Port\Out\ChannelRepositoryInterface;
use Dbots\Domain\Channel\Channel;
use Dbots\Domain\Channel\ChannelName;
use Dbots\Domain\Channel\ChannelSetting;
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

        // Older databases predate the topic column; add it in place.
        $columns = $this->pdo->query('PRAGMA table_info(channels)')->fetchAll(PDO::FETCH_ASSOC);

        if (!in_array('topic', array_column($columns, 'name'), true)) {
            $this->pdo->exec('ALTER TABLE channels ADD COLUMN topic TEXT NULL');
        }

        foreach (['password_hash', 'description', 'url', 'email', 'entry_message'] as $column) {
            if (!in_array($column, array_column($columns, 'name'), true)) {
                $this->pdo->exec(sprintf('ALTER TABLE channels ADD COLUMN %s TEXT NULL', $column));
            }
        }
    }

    public function find(ChannelName $name): ?Channel
    {
        $statement = $this->pdo->prepare('SELECT name, founder, created_at, topic, password_hash, description, url, email, entry_message
             FROM channels WHERE name = :name');
        $statement->execute(['name' => $name->value]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return false === $row ? null : $this->hydrate($row);
    }

    public function save(Channel $channel): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO channels (name, founder, created_at, topic, password_hash, description, url, email, entry_message)
             VALUES (:name, :founder, :created, :topic, :hash, :description, :url, :email, :entry)'
        );

        $statement->execute([
            'name' => $channel->name->value,
            'founder' => $channel->founder,
            'created' => $channel->createdAt->format('c'),
            'topic' => $channel->topic,
            'hash' => $channel->passwordHash,
            'description' => $channel->description,
            'url' => $channel->url,
            'email' => $channel->email,
            'entry' => $channel->entryMessage,
        ]);
    }

    public function updateFounder(Channel $channel): void
    {
        $statement = $this->pdo->prepare('UPDATE channels SET founder = :founder WHERE name = :name');
        $statement->execute(['name' => $channel->name->value, 'founder' => $channel->founder]);
    }

    public function updateTopic(Channel $channel): void
    {
        $statement = $this->pdo->prepare('UPDATE channels SET topic = :topic WHERE name = :name');
        $statement->execute(['name' => $channel->name->value, 'topic' => $channel->topic]);
    }

    public function updateSetting(ChannelName $name, ChannelSetting $setting, ?string $value): void
    {
        // The column name comes from the enum, never from user input.
        $statement = $this->pdo->prepare(sprintf(
            'UPDATE channels SET %s = :value WHERE name = :name',
            $setting->column(),
        ));
        $statement->execute(['name' => $name->value, 'value' => $value]);
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
        $statement = $this->pdo->query('SELECT name, founder, created_at, topic, password_hash, description, url, email, entry_message
             FROM channels ORDER BY name');

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
            null === ($row['topic'] ?? null) ? null : (string) $row['topic'],
            null === ($row['password_hash'] ?? null) ? null : (string) $row['password_hash'],
            null === ($row['description'] ?? null) ? null : (string) $row['description'],
            null === ($row['url'] ?? null) ? null : (string) $row['url'],
            null === ($row['email'] ?? null) ? null : (string) $row['email'],
            null === ($row['entry_message'] ?? null) ? null : (string) $row['entry_message'],
        );
    }
}
