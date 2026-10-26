<?php

declare(strict_types=1);

namespace Dbots\NoServ\Application\Port\Out;

interface MotdNickRepositoryInterface
{
    public function add(string $nick, string $message): void;

    public function remove(string $nick): bool;

    public function get(string $nick): ?string;

    /**
     * @return array<string, string> nick => message
     */
    public function all(): array;

    public function clear(): void;
}
