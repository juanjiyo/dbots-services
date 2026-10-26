<?php

declare(strict_types=1);

namespace Dbots\NoServ\Adapter\Out\Persistence;

use Dbots\NoServ\Application\Port\Out\NewsMessageRepositoryInterface;
use PDO;

final readonly class SqliteNewsMessageRepository implements NewsMessageRepositoryInterface
{
    public function __construct(
        private PDO $pdo,
        private string $type,
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS news_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL COLLATE NOCASE,
                author TEXT NOT NULL COLLATE NOCASE,
                date TEXT NOT NULL,
                text TEXT NOT NULL
            )'
        );
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_news_messages_type ON news_messages(type)');
    }

    public function add(string $author, string $text): int
    {
        $date = date('d/m/Y');
        $statement = $this->pdo->prepare(
            'INSERT INTO news_messages (type, author, date, text) VALUES (:type, :author, :date, :text)'
        );
        $statement->execute([
            'type' => $this->type,
            'author' => $this->normalize($author),
            'date' => $date,
            'text' => $text,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function remove(int $position): bool
    {
        $id = $this->idAtPosition($position);

        if (null === $id) {
            return false;
        }

        $statement = $this->pdo->prepare('DELETE FROM news_messages WHERE id = :id AND type = :type');
        $statement->execute(['id' => $id, 'type' => $this->type]);

        return $statement->rowCount() > 0;
    }

    public function all(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT author, date, text FROM news_messages WHERE type = :type ORDER BY id'
        );
        $statement->execute(['type' => $this->type]);
        $items = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'author' => (string) $row['author'],
                'date' => (string) $row['date'],
                'text' => (string) $row['text'],
            ];
        }

        return $items;
    }

    public function clear(): void
    {
        $statement = $this->pdo->prepare('DELETE FROM news_messages WHERE type = :type');
        $statement->execute(['type' => $this->type]);
    }

    private function idAtPosition(int $position): ?int
    {
        if ($position < 1) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT id FROM news_messages WHERE type = :type ORDER BY id LIMIT 1 OFFSET :offset'
        );
        $statement->execute(['type' => $this->type, 'offset' => $position - 1]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return false === $row ? null : (int) $row['id'];
    }

    private function normalize(string $value): string
    {
        return strtolower(trim($value));
    }
}
