<?php

declare(strict_types=1);

namespace Dbots\MemoServ\Adapter\Out\Persistence;

use Dbots\Domain\Memo\MemoNotifyMode;
use Dbots\MemoServ\Application\Port\Out\MemoPreferencesRepositoryInterface;
use PDO;

final readonly class SqliteMemoPreferencesRepository implements MemoPreferencesRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS memo_notify_modes (
                account TEXT PRIMARY KEY COLLATE NOCASE,
                mode TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS memo_blacklist (
                recipient TEXT NOT NULL COLLATE NOCASE,
                sender TEXT NOT NULL COLLATE NOCASE,
                PRIMARY KEY (recipient, sender)
            )'
        );
    }

    public function getNotifyMode(string $account): MemoNotifyMode
    {
        $statement = $this->pdo->prepare(
            'SELECT mode FROM memo_notify_modes WHERE account = :account'
        );
        $statement->execute(['account' => $account]);

        $mode = $statement->fetchColumn();

        if (false === $mode || null === $mode) {
            return MemoNotifyMode::On;
        }

        return MemoNotifyMode::fromString((string) $mode) ?? MemoNotifyMode::On;
    }

    public function setNotifyMode(string $account, MemoNotifyMode $mode): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO memo_notify_modes (account, mode) VALUES (:account, :mode)
             ON CONFLICT(account) DO UPDATE SET mode = excluded.mode'
        );
        $statement->execute(['account' => $account, 'mode' => $mode->value]);
    }

    public function isBlacklisted(string $recipient, string $sender): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM memo_blacklist WHERE recipient = :recipient AND sender = :sender'
        );
        $statement->execute(['recipient' => $recipient, 'sender' => $sender]);

        return false !== $statement->fetchColumn();
    }

    public function addToBlacklist(string $recipient, string $sender): void
    {
        $statement = $this->pdo->prepare(
            'INSERT OR IGNORE INTO memo_blacklist (recipient, sender) VALUES (:recipient, :sender)'
        );
        $statement->execute(['recipient' => $recipient, 'sender' => $sender]);
    }

    public function removeFromBlacklist(string $recipient, string $sender): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM memo_blacklist WHERE recipient = :recipient AND sender = :sender'
        );
        $statement->execute(['recipient' => $recipient, 'sender' => $sender]);

        return $statement->rowCount() > 0;
    }

    public function listBlacklist(string $recipient): array
    {
        $statement = $this->pdo->prepare(
            'SELECT sender FROM memo_blacklist WHERE recipient = :recipient ORDER BY sender'
        );
        $statement->execute(['recipient' => $recipient]);

        $senders = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $senders[] = (string) $row['sender'];
        }

        return $senders;
    }
}
