<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\Port\Out;

interface BlackmailRepositoryInterface
{
    public function add(string $domain): void;

    public function remove(string $domain): bool;

    public function contains(string $domain): bool;

    /**
     * @return list<string>
     */
    public function all(): array;

    public function clear(): void;
}
