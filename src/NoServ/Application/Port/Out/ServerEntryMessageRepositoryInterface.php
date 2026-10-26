<?php

declare(strict_types=1);

namespace Dbots\NoServ\Application\Port\Out;

interface ServerEntryMessageRepositoryInterface
{
    public function set(string $server, string $message): void;

    public function remove(string $server): bool;

    public function get(string $server): ?string;

    /**
     * @return array<string, string> server => message
     */
    public function all(): array;
}
