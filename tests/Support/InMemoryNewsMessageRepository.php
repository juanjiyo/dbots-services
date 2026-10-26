<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use Dbots\NoServ\Application\Port\Out\NewsMessageRepositoryInterface;

final class InMemoryNewsMessageRepository implements NewsMessageRepositoryInterface
{
    /** @var list<array{author: string, date: string, text: string}> */
    private array $items = [];

    public function add(string $author, string $text): int
    {
        $this->items[] = [
            'author' => strtolower(trim($author)),
            'date' => date('d/m/Y'),
            'text' => $text,
        ];

        return count($this->items);
    }

    public function remove(int $position): bool
    {
        if ($position < 1 || $position > count($this->items)) {
            return false;
        }

        unset($this->items[$position - 1]);
        $this->items = array_values($this->items);

        return true;
    }

    public function all(): array
    {
        return $this->items;
    }

    public function clear(): void
    {
        $this->items = [];
    }
}
