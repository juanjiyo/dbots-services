<?php

declare(strict_types=1);

namespace Dbots\NickServ\Adapter\Out\Persistence;

use Dbots\NickServ\Application\Port\Out\BlackmailRepositoryInterface;
use PDO;

final readonly class SqliteBlackmailRepository implements BlackmailRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS blackmail_domains (
                domain TEXT PRIMARY KEY COLLATE NOCASE
            )'
        );
    }

    public function add(string $domain): void
    {
        $statement = $this->pdo->prepare('INSERT OR IGNORE INTO blackmail_domains (domain) VALUES (:domain)');
        $statement->execute(['domain' => $this->normalize($domain)]);
    }

    public function remove(string $domain): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM blackmail_domains WHERE domain = :domain');
        $statement->execute(['domain' => $this->normalize($domain)]);

        return $statement->rowCount() > 0;
    }

    public function contains(string $domain): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM blackmail_domains WHERE domain = :domain');
        $statement->execute(['domain' => $this->normalize($domain)]);

        return false !== $statement->fetch(PDO::FETCH_ASSOC);
    }

    public function all(): array
    {
        $statement = $this->pdo->query('SELECT domain FROM blackmail_domains ORDER BY domain');
        $domains = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $domains[] = (string) $row['domain'];
        }

        return $domains;
    }

    public function clear(): void
    {
        $this->pdo->exec('DELETE FROM blackmail_domains');
    }

    private function normalize(string $domain): string
    {
        return strtolower(trim($domain, '@ '));
    }
}
