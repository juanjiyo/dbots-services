<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use Dbots\NickServ\Application\Port\Out\BlackmailRepositoryInterface;

final class InMemoryBlackmailRepository implements BlackmailRepositoryInterface
{
    /** @var array<string, true> */
    private array $domains = [];

    public function add(string $domain): void
    {
        $this->domains[$this->normalize($domain)] = true;
    }

    public function remove(string $domain): bool
    {
        $key = $this->normalize($domain);

        if (!isset($this->domains[$key])) {
            return false;
        }

        unset($this->domains[$key]);

        return true;
    }

    public function contains(string $domain): bool
    {
        return isset($this->domains[$this->normalize($domain)]);
    }

    public function all(): array
    {
        $domains = array_keys($this->domains);
        sort($domains);

        return $domains;
    }

    public function clear(): void
    {
        $this->domains = [];
    }

    private function normalize(string $domain): string
    {
        return strtolower(trim($domain, '@ '));
    }
}
