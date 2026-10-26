<?php

declare(strict_types=1);

namespace Dbots\CenTeRServ\Adapter\Out\Persistence;

use Dbots\CenTeRServ\Application\Port\Out\RawBlocklistRepositoryInterface;
use PDO;

final readonly class SqliteRawBlocklistRepository implements RawBlocklistRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS raw_blocklist (
                command TEXT PRIMARY KEY COLLATE NOCASE
            )'
        );

        foreach ($this->defaultCommands() as $command) {
            $this->add($command);
        }
    }

    public function add(string $command): void
    {
        $statement = $this->pdo->prepare('INSERT OR IGNORE INTO raw_blocklist (command) VALUES (:command)');
        $statement->execute(['command' => $this->normalize($command)]);
    }

    public function remove(string $command): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM raw_blocklist WHERE command = :command');
        $statement->execute(['command' => $this->normalize($command)]);

        return $statement->rowCount() > 0;
    }

    public function contains(string $command): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM raw_blocklist WHERE command = :command');
        $statement->execute(['command' => $this->normalize($command)]);

        return false !== $statement->fetch(PDO::FETCH_ASSOC);
    }

    public function all(): array
    {
        $statement = $this->pdo->query('SELECT command FROM raw_blocklist ORDER BY command');
        $commands = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $commands[] = (string) $row['command'];
        }

        return $commands;
    }

    public function clear(): void
    {
        $this->pdo->exec('DELETE FROM raw_blocklist');
    }

    /**
     * @return list<string>
     */
    private function defaultCommands(): array
    {
        return [
            'SQUIT',
            'CONNECT',
            'KILL',
            'GLINE',
            'UNGLINE',
            'KLINE',
            'UNKLINE',
            'RESTART',
            'DIE',
            'OPER',
            'LOCOP',
            'SDIE',
        ];
    }

    private function normalize(string $command): string
    {
        return strtoupper(trim($command));
    }
}
