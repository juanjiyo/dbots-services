<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use Dbots\Application\Port\Out\LinkTransportInterface;

final class RecordingTransport implements LinkTransportInterface
{
    /** @var list<string> */
    public array $written = [];

    /** @var list<string> */
    public array $incoming = [];

    public function open(): void {}

    public function readLine(): ?string
    {
        return array_shift($this->incoming);
    }

    public function writeLine(string $line): void
    {
        $this->written[] = $line;
    }

    public function isOpen(): bool
    {
        return true;
    }

    public function close(): void {}
}
