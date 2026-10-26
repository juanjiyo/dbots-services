<?php

declare(strict_types=1);

namespace Dbots\NoServ\Application\Port\Out;

interface NewsMessageRepositoryInterface
{
    /**
     * Stores a news item and returns its 1-based position.
     */
    public function add(string $author, string $text): int;

    /**
     * Removes the item at the given 1-based position.
     */
    public function remove(int $position): bool;

    /**
     * @return list<array{author: string, date: string, text: string}>
     */
    public function all(): array;

    public function clear(): void;
}
