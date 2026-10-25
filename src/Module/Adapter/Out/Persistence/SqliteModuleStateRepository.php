<?php

declare(strict_types=1);

namespace Dbots\Module\Adapter\Out\Persistence;

use Dbots\Module\ModuleStateRepositoryInterface;
use PDO;

final readonly class SqliteModuleStateRepository implements ModuleStateRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS modules (
                name TEXT PRIMARY KEY COLLATE NOCASE,
                enabled INTEGER NOT NULL
            )'
        );
    }

    public function isEnabled(string $name, bool $default): bool
    {
        $statement = $this->pdo->prepare('SELECT enabled FROM modules WHERE name = :name');
        $statement->execute(['name' => $name]);

        $value = $statement->fetchColumn();

        if (false === $value || null === $value) {
            $this->setEnabled($name, $default);

            return $default;
        }

        return 1 === (int) $value;
    }

    public function setEnabled(string $name, bool $enabled): void
    {
        $statement = $this->pdo->prepare(
            'INSERT OR REPLACE INTO modules (name, enabled) VALUES (:name, :enabled)'
        );
        $statement->execute(['name' => $name, 'enabled' => $enabled ? 1 : 0]);
    }

    public function all(): array
    {
        $statement = $this->pdo->query('SELECT name, enabled FROM modules ORDER BY name');

        $state = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $state[(string) $row['name']] = 1 === (int) $row['enabled'];
        }

        return $state;
    }
}
