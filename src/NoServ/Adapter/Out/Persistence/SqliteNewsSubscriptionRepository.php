<?php

declare(strict_types=1);

namespace Dbots\NoServ\Adapter\Out\Persistence;

use Dbots\NoServ\Application\Port\Out\NewsSubscriptionRepositoryInterface;
use PDO;

final readonly class SqliteNewsSubscriptionRepository implements NewsSubscriptionRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS news_subscriptions (
                account TEXT PRIMARY KEY COLLATE NOCASE
            )'
        );
    }

    public function subscribe(string $account): void
    {
        $statement = $this->pdo->prepare('INSERT OR IGNORE INTO news_subscriptions (account) VALUES (:account)');
        $statement->execute(['account' => $this->normalize($account)]);
    }

    public function unsubscribe(string $account): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM news_subscriptions WHERE account = :account');
        $statement->execute(['account' => $this->normalize($account)]);

        return $statement->rowCount() > 0;
    }

    public function isSubscribed(string $account): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM news_subscriptions WHERE account = :account');
        $statement->execute(['account' => $this->normalize($account)]);

        return false !== $statement->fetch(PDO::FETCH_ASSOC);
    }

    public function allSubscribers(): array
    {
        $statement = $this->pdo->query('SELECT account FROM news_subscriptions ORDER BY account');
        $subscribers = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $subscribers[] = (string) $row['account'];
        }

        return $subscribers;
    }

    private function normalize(string $account): string
    {
        return strtolower(trim($account));
    }
}
