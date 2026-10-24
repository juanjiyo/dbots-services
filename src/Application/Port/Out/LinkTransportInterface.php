<?php

declare(strict_types=1);

namespace Dbots\Application\Port\Out;

/**
 * A line-oriented, already-connected transport. Keeps the protocol layer free
 * of sockets, TLS and stream juggling.
 */
interface LinkTransportInterface
{
    public function open(): void;

    public function readLine(): ?string;

    public function writeLine(string $line): void;

    public function isOpen(): bool;

    public function close(): void;
}
