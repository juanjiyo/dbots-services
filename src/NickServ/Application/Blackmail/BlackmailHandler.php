<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\Blackmail;

use Dbots\NickServ\Application\Port\Out\BlackmailRepositoryInterface;

final readonly class BlackmailHandler
{
    public function __construct(private BlackmailRepositoryInterface $blackmail)
    {
    }

    public function add(string $domain): void
    {
        $this->blackmail->add($domain);
    }

    public function remove(string $domain): bool
    {
        return $this->blackmail->remove($domain);
    }

    /**
     * @return list<string>
     */
    public function list(): array
    {
        return $this->blackmail->all();
    }

    public function clear(): void
    {
        $this->blackmail->clear();
    }

    public function isBlacklisted(string $email): bool
    {
        $domain = $this->extractDomain($email);

        if (null === $domain) {
            return false;
        }

        return $this->blackmail->contains($domain);
    }

    private function extractDomain(string $email): ?string
    {
        $email = trim($email);
        $at = strrpos($email, '@');

        if (false === $at) {
            return null;
        }

        $domain = substr($email, $at + 1);

        return '' === $domain ? null : strtolower($domain);
    }
}
