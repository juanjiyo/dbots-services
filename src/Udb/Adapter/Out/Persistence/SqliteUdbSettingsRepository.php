<?php

declare(strict_types=1);

namespace Dbots\Udb\Adapter\Out\Persistence;

use Dbots\Udb\Application\Port\Out\UdbSettingsRepositoryInterface;
use PDO;

final readonly class SqliteUdbSettingsRepository implements UdbSettingsRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS udb_settings (
                key TEXT PRIMARY KEY COLLATE NOCASE,
                value TEXT NOT NULL
            )'
        );
    }

    public function get(string $key): ?string
    {
        $statement = $this->pdo->prepare('SELECT value FROM udb_settings WHERE key = :key');
        $statement->execute(['key' => $key]);

        $value = $statement->fetchColumn();

        return false === $value || null === $value ? null : (string) $value;
    }

    public function set(string $key, string $value): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO udb_settings (key, value) VALUES (:key, :value)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $statement->execute(['key' => $key, 'value' => $value]);
    }

    public function all(): array
    {
        $statement = $this->pdo->query('SELECT key, value FROM udb_settings ORDER BY key');
        $settings = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $settings[(string) $row['key']] = (string) $row['value'];
        }

        return $settings;
    }
}
