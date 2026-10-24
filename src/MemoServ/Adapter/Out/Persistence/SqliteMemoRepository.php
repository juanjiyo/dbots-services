<?php

declare(strict_types=1);

namespace Dbots\MemoServ\Adapter\Out\Persistence;

use DateTimeImmutable;
use Dbots\Domain\Memo\Memo;
use Dbots\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use PDO;

final readonly class SqliteMemoRepository implements MemoRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS memos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                recipient TEXT NOT NULL COLLATE NOCASE,
                sender TEXT NOT NULL,
                body TEXT NOT NULL,
                sent_at TEXT NOT NULL,
                read_at TEXT NULL
            )'
        );
    }

    public function save(Memo $memo): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO memos (recipient, sender, body, sent_at) VALUES (:recipient, :sender, :body, :sent)'
        );

        $statement->execute([
            'recipient' => $memo->recipient,
            'sender' => $memo->sender,
            'body' => $memo->body,
            'sent' => $memo->sentAt->format('c'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return list<Memo>
     */
    public function forRecipient(string $account): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, recipient, sender, body, sent_at, read_at FROM memos WHERE recipient = :account ORDER BY id'
        );
        $statement->execute(['account' => $account]);

        $memos = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $memos[] = $this->hydrate($row);
        }

        return $memos;
    }

    public function find(int $id, string $account): ?Memo
    {
        $statement = $this->pdo->prepare(
            'SELECT id, recipient, sender, body, sent_at, read_at FROM memos WHERE id = :id AND recipient = :account'
        );
        $statement->execute(['id' => $id, 'account' => $account]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return false === $row ? null : $this->hydrate($row);
    }

    public function markRead(int $id, string $account): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE memos SET read_at = :read WHERE id = :id AND recipient = :account AND read_at IS NULL'
        );
        $statement->execute(['id' => $id, 'account' => $account, 'read' => (new DateTimeImmutable())->format('c')]);
    }

    public function delete(int $id, string $account): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM memos WHERE id = :id AND recipient = :account');
        $statement->execute(['id' => $id, 'account' => $account]);

        return $statement->rowCount() > 0;
    }

    public function countUnread(string $account): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM memos WHERE recipient = :account AND read_at IS NULL'
        );
        $statement->execute(['account' => $account]);

        return (int) $statement->fetchColumn();
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Memo
    {
        return new Memo(
            (int) $row['id'],
            (string) $row['recipient'],
            (string) $row['sender'],
            (string) $row['body'],
            new DateTimeImmutable((string) $row['sent_at']),
            null === $row['read_at'] ? null : new DateTimeImmutable((string) $row['read_at']),
        );
    }
}
