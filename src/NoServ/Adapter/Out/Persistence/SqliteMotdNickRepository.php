<?php

declare(strict_types=1);

namespace Dbots\NoServ\Adapter\Out\Persistence;

use Dbots\NoServ\Application\Port\Out\MotdNickRepositoryInterface;
use PDO;

final readonly class SqliteMotdNickRepository implements MotdNickRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS motd_nicks (
                nick TEXT PRIMARY KEY COLLATE NOCASE,
                message TEXT NOT NULL
            )'
        );
    }

    public function add(string $nick, string $message): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO motd_nicks (nick, message) VALUES (:nick, :message)
             ON CONFLICT(nick) DO UPDATE SET message = excluded.message'
        );
        $statement->execute(['nick' => $this->normalize($nick), 'message' => $message]);
    }

    public function remove(string $nick): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM motd_nicks WHERE nick = :nick');
        $statement->execute(['nick' => $this->normalize($nick)]);

        return $statement->rowCount() > 0;
    }

    public function get(string $nick): ?string
    {
        $statement = $this->pdo->prepare('SELECT message FROM motd_nicks WHERE nick = :nick');
        $statement->execute(['nick' => $this->normalize($nick)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return false === $row ? null : (string) $row['message'];
    }

    public function all(): array
    {
        $statement = $this->pdo->query('SELECT nick, message FROM motd_nicks ORDER BY nick');
        $items = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[(string) $row['nick']] = (string) $row['message'];
        }

        return $items;
    }

    public function clear(): void
    {
        $this->pdo->exec('DELETE FROM motd_nicks');
    }

    private function normalize(string $nick): string
    {
        return strtolower(trim($nick));
    }
}
