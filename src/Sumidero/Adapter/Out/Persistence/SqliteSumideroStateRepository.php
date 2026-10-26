<?php

declare(strict_types=1);

namespace Dbots\Sumidero\Adapter\Out\Persistence;

use Dbots\Sumidero\Application\Port\Out\SumideroStateRepositoryInterface;
use PDO;

final readonly class SqliteSumideroStateRepository implements SumideroStateRepositoryInterface
{
    public function __construct(
        private PDO $pdo,
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->ensureSchema();
    }

    public function addShun(string $nick, string $mask, string $host, ?string $ip, int $until, string $reason): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO sumidero_shuns (nick, mask, host, ip, until, reason) '
            . 'VALUES (:nick, :mask, :host, :ip, :until, :reason) '
            . 'ON CONFLICT(nick) DO UPDATE SET mask=:mask, host=:host, ip=:ip, until=:until, reason=:reason'
        );
        $statement->execute([
            'nick' => strtolower($nick),
            'mask' => $mask,
            'host' => strtolower($host),
            'ip' => $ip,
            'until' => $until,
            'reason' => $reason,
        ]);
    }

    public function findShun(string $nick): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM sumidero_shuns WHERE nick = :nick');
        $statement->execute(['nick' => strtolower($nick)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return false === $row ? null : $row;
    }

    public function allShuns(): array
    {
        $statement = $this->pdo->query('SELECT * FROM sumidero_shuns ORDER BY until DESC');

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function removeShun(string $nick): void
    {
        $statement = $this->pdo->prepare('DELETE FROM sumidero_shuns WHERE nick = :nick');
        $statement->execute(['nick' => strtolower($nick)]);
    }

    public function removeShunByMask(string $mask): void
    {
        $statement = $this->pdo->prepare('DELETE FROM sumidero_shuns WHERE mask = :mask');
        $statement->execute(['mask' => $mask]);
    }

    public function removeTokensForHost(string $host): void
    {
        $statement = $this->pdo->prepare('DELETE FROM sumidero_tokens WHERE host = :host');
        $statement->execute(['host' => strtolower($host)]);
    }

    public function addToken(string $token, array $data): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO sumidero_tokens (token, nick, host, ip, since, reason) '
            . 'VALUES (:token, :nick, :host, :ip, :since, :reason)'
        );
        $statement->execute([
            'token' => $token,
            'nick' => strtolower($data['nick'] ?? ''),
            'host' => strtolower($data['host'] ?? ''),
            'ip' => $data['ip'] ?? null,
            'since' => $data['since'] ?? 0,
            'reason' => $data['reason'] ?? '',
        ]);
    }

    public function findToken(string $token): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM sumidero_tokens WHERE token = :token');
        $statement->execute(['token' => $token]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return false === $row ? null : $row;
    }

    public function removeToken(string $token): void
    {
        $statement = $this->pdo->prepare('DELETE FROM sumidero_tokens WHERE token = :token');
        $statement->execute(['token' => $token]);
    }

    public function bumpOffense(string $host): int
    {
        $this->pdo->prepare(
            'INSERT INTO sumidero_offenses (host, count) VALUES (:host, 1) '
            . 'ON CONFLICT(host) DO UPDATE SET count = count + 1'
        )->execute(['host' => strtolower($host)]);

        $statement = $this->pdo->prepare('SELECT count FROM sumidero_offenses WHERE host = :host');
        $statement->execute(['host' => strtolower($host)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return false === $row ? 1 : (int) $row['count'];
    }

    public function offenseCount(string $host): int
    {
        $statement = $this->pdo->prepare('SELECT count FROM sumidero_offenses WHERE host = :host');
        $statement->execute(['host' => strtolower($host)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return false === $row ? 0 : (int) $row['count'];
    }

    public function addIgnored(string $pattern): void
    {
        $this->pdo->prepare(
            'INSERT INTO sumidero_ignored (pattern) VALUES (:pattern) ON CONFLICT(pattern) DO NOTHING'
        )->execute(['pattern' => strtolower($pattern)]);
    }

    public function removeIgnored(string $pattern): void
    {
        $this->pdo->prepare('DELETE FROM sumidero_ignored WHERE pattern = :pattern')
            ->execute(['pattern' => strtolower($pattern)]);
    }

    public function allIgnored(): array
    {
        $statement = $this->pdo->query('SELECT pattern FROM sumidero_ignored ORDER BY pattern');
        $patterns = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $patterns[] = (string) $row['pattern'];
        }

        return $patterns;
    }

    public function addOperator(string $pattern): void
    {
        $this->pdo->prepare(
            'INSERT INTO sumidero_operators (pattern) VALUES (:pattern) ON CONFLICT(pattern) DO NOTHING'
        )->execute(['pattern' => strtolower($pattern)]);
    }

    public function removeOperator(string $pattern): void
    {
        $this->pdo->prepare('DELETE FROM sumidero_operators WHERE pattern = :pattern')
            ->execute(['pattern' => strtolower($pattern)]);
    }

    public function allOperators(): array
    {
        $statement = $this->pdo->query('SELECT pattern FROM sumidero_operators ORDER BY pattern');
        $patterns = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $patterns[] = (string) $row['pattern'];
        }

        return $patterns;
    }

    public function addSpamPattern(string $pattern): int
    {
        $this->pdo->prepare('INSERT INTO sumidero_spam_patterns (pattern) VALUES (:pattern)')
            ->execute(['pattern' => $pattern]);

        return (int) $this->pdo->lastInsertId();
    }

    public function removeSpamPattern(int $position): bool
    {
        $id = $this->idAtPosition($position);

        if (null === $id) {
            return false;
        }

        $statement = $this->pdo->prepare('DELETE FROM sumidero_spam_patterns WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->rowCount() > 0;
    }

    public function allSpamPatterns(): array
    {
        $statement = $this->pdo->query('SELECT id, pattern FROM sumidero_spam_patterns ORDER BY id');
        $patterns = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $patterns[] = [
                'id' => (int) $row['id'],
                'pattern' => (string) $row['pattern'],
            ];
        }

        return $patterns;
    }

    public function clearSpamPatterns(): void
    {
        $this->pdo->exec('DELETE FROM sumidero_spam_patterns');
    }

    private function idAtPosition(int $position): ?int
    {
        if ($position < 1) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT id FROM sumidero_spam_patterns ORDER BY id LIMIT 1 OFFSET :offset'
        );
        $statement->execute(['offset' => $position - 1]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return false === $row ? null : (int) $row['id'];
    }

    private function ensureSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS sumidero_shuns (
                nick TEXT PRIMARY KEY COLLATE NOCASE,
                mask TEXT NOT NULL,
                host TEXT NOT NULL COLLATE NOCASE,
                ip TEXT,
                until INTEGER NOT NULL,
                reason TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS sumidero_tokens (
                token TEXT PRIMARY KEY,
                nick TEXT NOT NULL COLLATE NOCASE,
                host TEXT NOT NULL COLLATE NOCASE,
                ip TEXT,
                since INTEGER NOT NULL,
                reason TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS sumidero_offenses (
                host TEXT PRIMARY KEY COLLATE NOCASE,
                count INTEGER NOT NULL DEFAULT 1
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS sumidero_ignored (
                pattern TEXT PRIMARY KEY COLLATE NOCASE
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS sumidero_operators (
                pattern TEXT PRIMARY KEY COLLATE NOCASE
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS sumidero_spam_patterns (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                pattern TEXT NOT NULL
            )'
        );
    }
}
