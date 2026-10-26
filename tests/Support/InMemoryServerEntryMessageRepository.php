<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use Dbots\NoServ\Application\Port\Out\ServerEntryMessageRepositoryInterface;

final class InMemoryServerEntryMessageRepository implements ServerEntryMessageRepositoryInterface
{
    /** @var array<string, string> */
    private array $messages = [];

    public function set(string $server, string $message): void
    {
        $this->messages[$this->normalize($server)] = $message;
    }

    public function remove(string $server): bool
    {
        $key = $this->normalize($server);

        if (!isset($this->messages[$key])) {
            return false;
        }

        unset($this->messages[$key]);

        return true;
    }

    public function get(string $server): ?string
    {
        return $this->messages[$this->normalize($server)] ?? null;
    }

    public function all(): array
    {
        ksort($this->messages);

        return $this->messages;
    }

    private function normalize(string $server): string
    {
        return strtolower(trim($server));
    }
}
