<?php

declare(strict_types=1);

namespace Dbots\ClonesServ\Adapter\Out\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Dbots\ClonesServ\Application\Port\Out\ClonesExceptionRepositoryInterface;
use Dbots\ClonesServ\Domain\ClonesException;
use PDO;

use function strtolower;

final readonly class SqliteClonesExceptionRepository implements ClonesExceptionRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS clones_exceptions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                host TEXT UNIQUE NOT NULL COLLATE NOCASE,
                clones INTEGER NOT NULL,
                type TEXT NOT NULL,
                author TEXT NOT NULL,
                created_at TEXT NOT NULL,
                expires_at TEXT
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS clones_nickmap (
                nick TEXT PRIMARY KEY,
                host TEXT NOT NULL COLLATE NOCASE
            )'
        );
    }

    public function save(ClonesException $exception): ClonesException
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO clones_exceptions (host, clones, type, author, created_at, expires_at)
             VALUES (:host, :clones, :type, :author, :created_at, :expires_at)
             ON CONFLICT(host) DO UPDATE SET
                 clones = excluded.clones,
                 type = excluded.type,
                 author = excluded.author,
                 created_at = excluded.created_at,
                 expires_at = excluded.expires_at'
        );

        $statement->execute([
            'host' => $exception->host,
            'clones' => $exception->clones,
            'type' => $exception->type,
            'author' => $exception->author,
            'created_at' => $exception->createdAt->format('Y-m-d H:i:s'),
            'expires_at' => null === $exception->expiresAt ? null : $exception->expiresAt->format('Y-m-d H:i:s'),
        ]);

        $id = (int) $this->pdo->lastInsertId();

        return new ClonesException(
            $id,
            $exception->host,
            $exception->clones,
            $exception->type,
            $exception->author,
            $exception->createdAt,
            $exception->expiresAt,
        );
    }

    public function findByHost(string $host): ?ClonesException
    {
        $statement = $this->pdo->prepare('SELECT * FROM clones_exceptions WHERE host = :host');
        $statement->execute(['host' => $host]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return false === $row ? null : $this->hydrate($row);
    }

    public function deleteByHost(string $host): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM clones_exceptions WHERE host = :host');
        $statement->execute(['host' => $host]);

        return $statement->rowCount() > 0;
    }

    /**
     * @return list<ClonesException>
     */
    public function all(): array
    {
        $exceptions = [];

        foreach ($this->pdo->query('SELECT * FROM clones_exceptions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $exceptions[] = $this->hydrate($row);
        }

        return $exceptions;
    }

    /**
     * @return list<ClonesException>
     */
    public function allActive(DateTimeImmutable $now): array
    {
        $active = [];

        foreach ($this->all() as $exception) {
            if (!$exception->isExpired($now)) {
                $active[] = $exception;
            }
        }

        return $active;
    }

    public function linkNick(string $nick, string $host): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO clones_nickmap (nick, host) VALUES (:nick, :host)
             ON CONFLICT(nick) DO UPDATE SET host = excluded.host'
        );
        $statement->execute([
            'nick' => strtolower($nick),
            'host' => $host,
        ]);

        $cleanup = $this->pdo->prepare(
            'DELETE FROM clones_nickmap WHERE nick <> :nick AND host = :host'
        );
        $cleanup->execute([
            'nick' => strtolower($nick),
            'host' => $host,
        ]);
    }

    public function hostForNick(string $nick): ?string
    {
        $statement = $this->pdo->prepare('SELECT host FROM clones_nickmap WHERE nick = :nick');
        $statement->execute(['nick' => strtolower($nick)]);

        $row = $statement->fetch(PDO::FETCH_COLUMN);

        return false === $row ? null : (string) $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ClonesException
    {
        return new ClonesException(
            (int) $row['id'],
            (string) $row['host'],
            (int) $row['clones'],
            (string) $row['type'],
            (string) $row['author'],
            new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')),
            null === $row['expires_at'] ? null : new DateTimeImmutable((string) $row['expires_at'], new DateTimeZone('UTC')),
        );
    }
}
