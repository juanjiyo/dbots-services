<?php

declare(strict_types=1);

namespace Dbots\NoServ\Adapter\Out\Persistence;

use Dbots\NoServ\Application\Port\Out\ServerEntryMessageRepositoryInterface;
use PDO;

final readonly class SqliteServerEntryMessageRepository implements ServerEntryMessageRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS server_entry_messages (
                server TEXT PRIMARY KEY COLLATE NOCASE,
                message TEXT NOT NULL
            )'
        );
    }

    public function set(string $server, string $message): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO server_entry_messages (server, message) VALUES (:server, :message)
             ON CONFLICT(server) DO UPDATE SET message = excluded.message'
        );
        $statement->execute(['server' => $this->normalize($server), 'message' => $message]);
    }

    public function remove(string $server): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM server_entry_messages WHERE server = :server');
        $statement->execute(['server' => $this->normalize($server)]);

        return $statement->rowCount() > 0;
    }

    public function get(string $server): ?string
    {
        $statement = $this->pdo->prepare('SELECT message FROM server_entry_messages WHERE server = :server');
        $statement->execute(['server' => $this->normalize($server)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return false === $row ? null : (string) $row['message'];
    }

    public function all(): array
    {
        $statement = $this->pdo->query('SELECT server, message FROM server_entry_messages ORDER BY server');
        $items = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[(string) $row['server']] = (string) $row['message'];
        }

        return $items;
    }

    private function normalize(string $server): string
    {
        return strtolower(trim($server));
    }
}
