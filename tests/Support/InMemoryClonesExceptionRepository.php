<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use DateTimeImmutable;
use Dbots\ClonesServ\Application\Port\Out\ClonesExceptionRepositoryInterface;
use Dbots\ClonesServ\Domain\ClonesException;

use function strtolower;

final class InMemoryClonesExceptionRepository implements ClonesExceptionRepositoryInterface
{
    /** @var array<int, ClonesException> */
    private array $exceptions = [];

    /** @var array<string, string> */
    private array $nickmap = [];

    private int $nextId = 1;

    public function save(ClonesException $exception): ClonesException
    {
        $id = $exception->id ?? $this->nextId++;
        $stored = new ClonesException(
            $id,
            $exception->host,
            $exception->clones,
            $exception->type,
            $exception->author,
            $exception->createdAt,
            $exception->expiresAt,
        );
        $this->exceptions[$id] = $stored;

        return $stored;
    }

    public function findByHost(string $host): ?ClonesException
    {
        foreach ($this->exceptions as $exception) {
            if (0 === strcasecmp($exception->host, $host)) {
                return $exception;
            }
        }

        return null;
    }

    public function deleteByHost(string $host): bool
    {
        foreach ($this->exceptions as $id => $exception) {
            if (0 === strcasecmp($exception->host, $host)) {
                unset($this->exceptions[$id]);

                return true;
            }
        }

        return false;
    }

    /**
     * @return list<ClonesException>
     */
    public function all(): array
    {
        return array_values($this->exceptions);
    }

    /**
     * @return list<ClonesException>
     */
    public function allActive(DateTimeImmutable $now): array
    {
        $active = [];

        foreach ($this->exceptions as $exception) {
            if (!$exception->isExpired($now)) {
                $active[] = $exception;
            }
        }

        return $active;
    }

    public function linkNick(string $nick, string $host): void
    {
        $nick = strtolower($nick);

        foreach ($this->nickmap as $mappedNick => $mappedHost) {
            if (0 === strcasecmp($mappedHost, $host) && $mappedNick !== $nick) {
                unset($this->nickmap[$mappedNick]);
            }
        }

        $this->nickmap[$nick] = $host;
    }

    public function hostForNick(string $nick): ?string
    {
        return $this->nickmap[strtolower($nick)] ?? null;
    }
}
