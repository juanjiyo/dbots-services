<?php

declare(strict_types=1);

namespace Dbots\NickServ\Adapter\Out\Persistence;

use DateTimeImmutable;
use Dbots\Domain\Account\Account;
use Dbots\Domain\Account\Nick;
use Dbots\NickServ\Application\Port\Out\AccountRepositoryInterface;
use PDO;
use RuntimeException;

use function sprintf;

/**
 * SQLite-backed account store. Schema is created on first use: there is no
 * migration tool yet and a single table does not justify one.
 */
final readonly class SqliteAccountRepository implements AccountRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS accounts (
                nick TEXT PRIMARY KEY COLLATE NOCASE,
                password_hash TEXT NOT NULL,
                email TEXT NULL,
                created_at TEXT NOT NULL
            )'
        );
    }

    public static function fromPath(string $path): self
    {
        return new self(new PDO('sqlite:' . $path));
    }

    public function findByNick(Nick $nick): ?Account
    {
        $statement = $this->pdo->prepare('SELECT nick, password_hash, email, created_at FROM accounts WHERE nick = :nick');
        $statement->execute(['nick' => $nick->value]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (false === $row) {
            return null;
        }

        return new Account(
            new Nick((string) $row['nick']),
            (string) $row['password_hash'],
            null === $row['email'] ? null : (string) $row['email'],
            new DateTimeImmutable((string) $row['created_at']),
        );
    }

    public function save(Account $account): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO accounts (nick, password_hash, email, created_at) VALUES (:nick, :hash, :email, :created)'
        );

        $statement->execute([
            'nick' => $account->nick->value,
            'hash' => $account->passwordHash,
            'email' => $account->email,
            'created' => $account->createdAt->format('c'),
        ]);
    }

    /**
     * @return list<Account>
     */
    public function all(): array
    {
        $statement = $this->pdo->query('SELECT nick, password_hash, email, created_at FROM accounts ORDER BY nick');

        $accounts = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $accounts[] = new Account(
                new Nick((string) $row['nick']),
                (string) $row['password_hash'],
                null === $row['email'] ? null : (string) $row['email'],
                new DateTimeImmutable((string) $row['created_at']),
            );
        }

        return $accounts;
    }
}
