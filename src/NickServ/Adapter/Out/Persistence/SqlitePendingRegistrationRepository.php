<?php

declare(strict_types=1);

namespace Dbots\NickServ\Adapter\Out\Persistence;

use DateTimeImmutable;
use Dbots\Domain\Account\Nick;
use Dbots\NickServ\Application\Port\Out\PendingRegistrationRepositoryInterface;
use Dbots\NickServ\Domain\PendingRegistration\PendingRegistration;
use PDO;

final readonly class SqlitePendingRegistrationRepository implements PendingRegistrationRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS pending_registrations (
                nick TEXT PRIMARY KEY COLLATE NOCASE,
                password_hash TEXT NOT NULL,
                email TEXT NULL,
                code TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                plain_password TEXT NULL
            )'
        );

        $columns = array_column($this->pdo->query('PRAGMA table_info(pending_registrations)')->fetchAll(PDO::FETCH_ASSOC), 'name');

        if (!in_array('plain_password', $columns, true)) {
            $this->pdo->exec('ALTER TABLE pending_registrations ADD COLUMN plain_password TEXT NULL');
        }
    }

    public function save(PendingRegistration $pending): void
    {
        $statement = $this->pdo->prepare(
            'INSERT OR REPLACE INTO pending_registrations (nick, password_hash, email, code, expires_at, plain_password)
             VALUES (:nick, :hash, :email, :code, :expires, :plain)'
        );
        $statement->execute([
            'nick' => $pending->nick->value,
            'hash' => $pending->passwordHash,
            'email' => $pending->email,
            'code' => $pending->code,
            'expires' => $pending->expiresAt->format('c'),
            'plain' => $pending->plainPassword,
        ]);
    }

    public function findByNick(Nick $nick): ?PendingRegistration
    {
        $statement = $this->pdo->prepare('SELECT * FROM pending_registrations WHERE nick = :nick');
        $statement->execute(['nick' => $nick->value]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (false === $row) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function remove(Nick $nick): void
    {
        $statement = $this->pdo->prepare('DELETE FROM pending_registrations WHERE nick = :nick');
        $statement->execute(['nick' => $nick->value]);
    }

    public function expiredBefore(DateTimeImmutable $before): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM pending_registrations WHERE expires_at < :before');
        $statement->execute(['before' => $before->format('c')]);

        $pendings = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pendings[] = $this->hydrate($row);
        }

        return $pendings;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): PendingRegistration
    {
        return new PendingRegistration(
            new Nick((string) $row['nick']),
            (string) $row['password_hash'],
            null === $row['email'] ? null : (string) $row['email'],
            (string) $row['code'],
            new DateTimeImmutable((string) $row['expires_at']),
            null === ($row['plain_password'] ?? null) ? null : (string) $row['plain_password'],
        );
    }
}
