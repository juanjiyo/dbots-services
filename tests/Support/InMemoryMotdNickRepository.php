<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use Dbots\NoServ\Application\Port\Out\MotdNickRepositoryInterface;

final class InMemoryMotdNickRepository implements MotdNickRepositoryInterface
{
    /** @var array<string, string> */
    private array $nicks = [];

    public function add(string $nick, string $message): void
    {
        $this->nicks[$this->normalize($nick)] = $message;
    }

    public function remove(string $nick): bool
    {
        $key = $this->normalize($nick);

        if (!isset($this->nicks[$key])) {
            return false;
        }

        unset($this->nicks[$key]);

        return true;
    }

    public function get(string $nick): ?string
    {
        return $this->nicks[$this->normalize($nick)] ?? null;
    }

    public function all(): array
    {
        ksort($this->nicks);

        return $this->nicks;
    }

    public function clear(): void
    {
        $this->nicks = [];
    }

    private function normalize(string $nick): string
    {
        return strtolower(trim($nick));
    }
}
