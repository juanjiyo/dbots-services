<?php

declare(strict_types=1);

namespace Dbots\Application\Link;

use Dbots\Application\Port\Out\LinkTransportInterface;
use Dbots\Domain\Link\LinkState;
use Dbots\Domain\Link\ServerLink;
use RuntimeException;

use function time;

/**
 * Drives a {@see Handshake} over a {@see LinkTransportInterface}.
 */
final class LinkRunner
{
    private readonly Handshake $handshake;

    public function __construct(
        ServerLink $link,
        private readonly LinkTransportInterface $transport,
        private readonly int $syncTimeoutSeconds = 30,
        string $networkName = 'IRC Network',
    ) {
        $this->handshake = new Handshake($link, $networkName);
    }

    public function handshake(): Handshake
    {
        return $this->handshake;
    }

    /** Connects and runs the handshake until the link is synced or fails. */
    public function connect(?callable $onLine = null): void
    {
        $this->transport->open();
        $this->dispatch($this->handshake->start());

        $deadline = time() + $this->syncTimeoutSeconds;

        while (LinkState::Synced !== $this->handshake->state()) {
            if (LinkState::Failed === $this->handshake->state()) {
                throw new RuntimeException('Link refused by the IRCd: ' . ($this->handshake->error() ?? 'unknown reason'));
            }

            if (time() > $deadline) {
                throw new RuntimeException('Link handshake timed out before the remote EOS.');
            }

            $line = $this->transport->readLine();

            if (null === $line) {
                throw new RuntimeException('Link closed by the IRCd during the handshake.');
            }

            $this->handshake->consume($line);
            $this->dispatch($this->handshake->flush());

            if (null !== $onLine) {
                $onLine($line);
            }
        }
    }

    /**
     * Reads and dispatches lines for the given number of seconds.
     *
     * Pass null to run until the transport closes.
     *
     * The optional handler sees every line after the handshake has processed
     * it, which is where the network state and command routing live.
     *
     * @param (callable(string): void)|null $onLine
     */
    public function pump(?int $seconds, ?callable $onLine = null): void
    {
        $deadline = null === $seconds ? null : time() + $seconds;

        while ((null === $deadline || time() <= $deadline) && $this->transport->isOpen()) {
            $line = $this->transport->readLine();

            if (null === $line) {
                continue;
            }

            $this->handshake->consume($line);
            $this->dispatch($this->handshake->flush());

            if (null !== $onLine) {
                $onLine($line);
            }
        }
    }

    public function close(): void
    {
        $this->transport->close();
    }

    /** @param list<string> $lines */
    private function dispatch(array $lines): void
    {
        foreach ($lines as $line) {
            $this->transport->writeLine($line);
        }
    }
}
