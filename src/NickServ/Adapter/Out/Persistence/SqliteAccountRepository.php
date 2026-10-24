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
                created_at TEXT NOT NULL,
                forbid_reason TEXT NULL,
                suspend_reason TEXT NULL,
                swhois TEXT NULL,
                vhost TEXT NULL,
                noexpire INTEGER NOT NULL DEFAULT 0
            )'
        );

        $columns = array_column($this->pdo->query('PRAGMA table_info(accounts)')->fetchAll(PDO::FETCH_ASSOC), 'name');

        foreach ([
            'forbid_reason' => 'TEXT NULL',
            'suspend_reason' => 'TEXT NULL',
            'swhois' => 'TEXT NULL',
            'vhost' => 'TEXT NULL',
            'noexpire' => 'INTEGER NOT NULL DEFAULT 0',
        ] as $column => $definition) {
            if (!in_array($column, $columns, true)) {
                $this->pdo->exec(sprintf('ALTER TABLE accounts ADD COLUMN %s %s', $column, $definition));
            }
        }
    }

    public static function fromPath(string $path): self
    {
        return new self(new PDO('sqlite:' . $path));
    }

    public function findByNick(Nick $nick): ?Account
    {
        $statement = $this->pdo->prepare('SELECT * FROM accounts WHERE nick = :nick');
        $statement->execute(['nick' => $nick->value]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (false === $row) {
            return null;
        }

        return $this->hydrate($row);
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

    public function update(Account $account): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE accounts SET password_hash = :hash, email = :email, forbid_reason = :forbid,
                suspend_reason = :suspend, swhois = :swhois, vhost = :vhost, noexpire = :noexpire
             WHERE nick = :nick'
        );

        $statement->execute([
            'nick' => $account->nick->value,
            'hash' => $account->passwordHash,
            'email' => $account->email,
            'forbid' => $account->forbidReason,
            'suspend' => $account->suspendReason,
            'swhois' => $account->swhois,
            'vhost' => $account->vhost,
            'noexpire' => $account->noexpire ? 1 : 0,
        ]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Account
    {
        return new Account(
            new Nick((string) $row['nick']),
            (string) $row['password_hash'],
            null === $row['email'] ? null : (string) $row['email'],
            new DateTimeImmutable((string) $row['created_at']),
            null === ($row['forbid_reason'] ?? null) ? null : (string) $row['forbid_reason'],
            null === ($row['suspend_reason'] ?? null) ? null : (string) $row['suspend_reason'],
            null === ($row['swhois'] ?? null) ? null : (string) $row['swhois'],
            null === ($row['vhost'] ?? null) ? null : (string) $row['vhost'],
            1 === (int) ($row['noexpire'] ?? 0),
        );
    }

    public function delete(Nick $nick): void
    {
        $statement = $this->pdo->prepare('DELETE FROM accounts WHERE nick = :nick');
        $statement->execute(['nick' => $nick->value]);
    }

    /**
     * @return list<Account>
     */
    public function all(): array
    {
        $statement = $this->pdo->query('SELECT * FROM accounts ORDER BY nick');

        $accounts = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $accounts[] = $this->hydrate($row);
        }

        return $accounts;
    }
}
