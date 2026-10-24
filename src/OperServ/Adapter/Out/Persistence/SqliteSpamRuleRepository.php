<?php

declare(strict_types=1);

namespace Dbots\OperServ\Adapter\Out\Persistence;

use Dbots\Domain\Spam\SpamRule;
use Dbots\OperServ\Application\Port\Out\SpamRuleRepositoryInterface;
use PDO;

final readonly class SqliteSpamRuleRepository implements SpamRuleRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS spam_rules (
                pattern TEXT PRIMARY KEY,
                action TEXT NOT NULL,
                targets TEXT NOT NULL,
                ban_time INTEGER NOT NULL,
                reason TEXT NOT NULL,
                expires INTEGER NOT NULL
            )'
        );
    }

    public function save(SpamRule $rule): void
    {
        $statement = $this->pdo->prepare(
            'INSERT OR REPLACE INTO spam_rules (pattern, action, targets, ban_time, reason, expires)
             VALUES (:pattern, :action, :targets, :ban_time, :reason, :expires)'
        );

        $statement->execute([
            'pattern' => $rule->pattern,
            'action' => $rule->action,
            'targets' => $rule->targets,
            'ban_time' => $rule->banTime,
            'reason' => $rule->reason,
            'expires' => $rule->expires,
        ]);
    }

    public function find(string $pattern): ?SpamRule
    {
        $statement = $this->pdo->prepare('SELECT * FROM spam_rules WHERE pattern = :pattern');
        $statement->execute(['pattern' => $pattern]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return false === $row ? null : $this->hydrate($row);
    }

    public function delete(string $pattern): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM spam_rules WHERE pattern = :pattern');
        $statement->execute(['pattern' => $pattern]);

        return $statement->rowCount() > 0;
    }

    /**
     * @return list<SpamRule>
     */
    public function all(): array
    {
        $rules = [];

        foreach ($this->pdo->query('SELECT * FROM spam_rules ORDER BY pattern')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rules[] = $this->hydrate($row);
        }

        return $rules;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): SpamRule
    {
        return new SpamRule(
            (string) $row['pattern'],
            (string) $row['action'],
            (string) $row['targets'],
            (int) $row['ban_time'],
            (string) $row['reason'],
            (int) $row['expires'],
        );
    }
}
