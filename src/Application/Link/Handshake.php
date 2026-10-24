<?php

declare(strict_types=1);

namespace Dbots\Application\Link;

use Dbots\Domain\Link\LinkState;
use Dbots\Domain\Link\ServerLink;
use LogicException;

use function implode;
use function preg_match;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function strpos;
use function substr;
use function time;
use function trim;

/**
 * Server-to-server handshake, expressed as lines in and lines out.
 *
 * The transport owns the socket; this class owns the protocol. That separation
 * is what makes the whole handshake unit-testable without an IRCd.
 */
final class Handshake
{
    /** Capabilities this daemon advertises. Kept to what we actually honour. */
    private const array CAPABILITIES = [
        'NOQUIT',
        'NICKv2',
        'SJOIN',
        'SJOIN2',
        'SJ3',
        'CLK',
        'TKLEXT',
        'TKLEXT2',
        'NICKIP',
        'ESVID',
        'UMODE2',
        'MLOCK',
        'EXTSWHOIS',
        'VHP',
        'BIGLINES',
        'MTAGS',
        'NEXTBANS',
        'SJSBY',
    ];

    private LinkState $state = LinkState::Disconnected;
    private ?string $remoteSid = null;
    private ?string $remoteName = null;
    private ?string $error = null;

    /** @var list<string> */
    private array $outbound = [];

    public function __construct(
        private readonly ServerLink $link,
        private readonly string $networkName = 'IRC Network',
    ) {
    }

    /** @return list<string> */
    public function start(): array
    {
        if (LinkState::Disconnected !== $this->state) {
            throw new LogicException('The handshake has already been started.');
        }

        $this->state = LinkState::Handshaking;
        $this->queue(sprintf('PASS :%s', $this->link->password));
        $this->queue(sprintf('PROTOCTL EAUTH=%s SID=%s', $this->link->name, $this->link->sid));
        $this->queue(sprintf('PROTOCTL %s', implode(' ', self::CAPABILITIES)));
        $this->queue(sprintf('SERVER %s 1 :%s', $this->link->name, $this->link->description));

        return $this->flush();
    }

    public function consume(string $line): void
    {
        if (LinkState::Failed === $this->state) {
            return;
        }

        if (str_starts_with($line, 'ERROR')) {
            $this->fail($this->trailing($line) ?? trim($line));

            return;
        }

        if (str_starts_with($line, 'PING ')) {
            $this->queue(sprintf(':%s PONG %s', $this->link->sid, substr($line, 5)));

            return;
        }

        if (str_starts_with($line, 'PROTOCTL ')) {
            $this->captureRemoteIdentity($line);

            return;
        }

        if (str_starts_with($line, 'NETINFO')) {
            $this->queue(sprintf('NETINFO 0 %d 6100 * 0 0 0 :%s', time(), $this->networkName));

            return;
        }

        if ($this->isSyncEnd($line)) {
            $this->state = LinkState::Synced;
            $this->queue(sprintf(':%s EOS', $this->link->sid));
        }
    }

    /** @return list<string> */
    public function flush(): array
    {
        $lines = $this->outbound;
        $this->outbound = [];

        return $lines;
    }

    public function state(): LinkState
    {
        return $this->state;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function remoteSid(): ?string
    {
        return $this->remoteSid;
    }

    public function remoteName(): ?string
    {
        return $this->remoteName;
    }

    public function fail(string $reason): void
    {
        $this->state = LinkState::Failed;
        $this->error = $reason;
    }

    private function isSyncEnd(string $line): bool
    {
        if (LinkState::Synced === $this->state || !str_ends_with($line, ' EOS')) {
            return false;
        }

        // A relayed EOS from another leaf server means that server finished its
        // burst, not that our peer is done. Only the peer's own EOS syncs us.
        return null === $this->remoteSid || str_starts_with($line, ':' . $this->remoteSid . ' ');
    }

    private function captureRemoteIdentity(string $line): void
    {
        if (preg_match('/SID=([0-9][0-9A-Z]{2})/', $line, $matches)) {
            $this->remoteSid = $matches[1];
        }

        if (preg_match('/EAUTH=(\S+)/', $line, $matches)) {
            $this->remoteName = $matches[1];
        }
    }

    private function trailing(string $line): ?string
    {
        $position = strpos($line, ' :');

        return false === $position ? null : substr($line, $position + 2);
    }

    private function queue(string $line): void
    {
        $this->outbound[] = $line;
    }
}
