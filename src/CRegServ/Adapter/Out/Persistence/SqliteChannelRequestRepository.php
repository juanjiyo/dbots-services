<?php

declare(strict_types=1);

namespace Dbots\CRegServ\Adapter\Out\Persistence;

use DateTimeImmutable;
use Dbots\CRegServ\Application\Port\Out\ChannelRequestRepositoryInterface;
use Dbots\Domain\Registration\ChannelKind;
use Dbots\Domain\Registration\ChannelRequest;
use Dbots\Domain\Registration\RequestStatus;
use PDO;

final readonly class SqliteChannelRequestRepository implements ChannelRequestRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS channel_requests (
                channel TEXT PRIMARY KEY,
                requester TEXT NOT NULL,
                description TEXT NOT NULL,
                status TEXT NOT NULL,
                requested_at TEXT NOT NULL,
                kind TEXT NOT NULL DEFAULT 'NORMAL',
                confirmation TEXT NOT NULL DEFAULT '',
                decided_by TEXT NULL,
                decided_at TEXT NULL,
                reason TEXT NULL
            )
            SQL);

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS creg_admins (nick TEXT PRIMARY KEY COLLATE NOCASE)');
    }

    public function find(string $channel): ?ChannelRequest
    {
        $statement = $this->pdo->prepare('SELECT * FROM channel_requests WHERE channel = :channel');
        $statement->execute(['channel' => $channel]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return false === $row ? null : $this->hydrate($row);
    }

    public function save(ChannelRequest $request): void
    {
        $statement = $this->pdo->prepare(
            'INSERT OR REPLACE INTO channel_requests
             (channel, requester, description, status, requested_at, kind, confirmation, decided_by, decided_at, reason)
             VALUES (:channel, :requester, :description, :status, :requested, :kind, :confirmation, :decidedBy, :decidedAt, :reason)'
        );

        $statement->execute([
            'channel' => $request->channel,
            'requester' => $request->requester,
            'description' => $request->description,
            'status' => $request->status->value,
            'requested' => $request->requestedAt->format('c'),
            'kind' => $request->kind->value,
            'confirmation' => $request->confirmation,
            'decidedBy' => $request->decidedBy,
            'decidedAt' => $request->decidedAt?->format('c'),
            'reason' => $request->reason,
        ]);
    }

    public function delete(string $channel): void
    {
        foreach (['channel_requests'] as $table) {
            $statement = $this->pdo->prepare(sprintf('DELETE FROM %s WHERE channel = :channel', $table));
            $statement->execute(['channel' => $channel]);
        }
    }

    public function byStatus(RequestStatus $status): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM channel_requests WHERE status = :status ORDER BY channel');
        $statement->execute(['status' => $status->value]);

        return $this->hydrateAll($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function all(): array
    {
        return $this->hydrateAll($this->pdo->query('SELECT * FROM channel_requests ORDER BY channel')->fetchAll(PDO::FETCH_ASSOC));
    }



    public function addAdmin(string $nick): void
    {
        $statement = $this->pdo->prepare('INSERT OR IGNORE INTO creg_admins (nick) VALUES (:nick)');
        $statement->execute(['nick' => $nick]);
    }

    public function removeAdmin(string $nick): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM creg_admins WHERE nick = :nick');
        $statement->execute(['nick' => $nick]);

        return $statement->rowCount() > 0;
    }

    public function isAdmin(string $nick): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM creg_admins WHERE nick = :nick');
        $statement->execute(['nick' => $nick]);

        return false !== $statement->fetchColumn();
    }

    public function admins(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['nick'],
            $this->pdo->query('SELECT nick FROM creg_admins ORDER BY nick')->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /**
     * @param  list<array<string, mixed>> $rows
     * @return list<ChannelRequest>
     */
    private function hydrateAll(array $rows): array
    {
        return array_map($this->hydrate(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ChannelRequest
    {
        return new ChannelRequest(
            (string) $row['channel'],
            (string) $row['requester'],
            (string) $row['description'],
            RequestStatus::from((string) $row['status']),
            new DateTimeImmutable((string) $row['requested_at']),
            ChannelKind::from((string) ($row['kind'] ?? 'NORMAL')),
            (string) ($row['confirmation'] ?? ''),
            null === $row['decided_by'] ? null : (string) $row['decided_by'],
            null === $row['decided_at'] ? null : new DateTimeImmutable((string) $row['decided_at']),
            null === $row['reason'] ? null : (string) $row['reason'],
        );
    }
}
