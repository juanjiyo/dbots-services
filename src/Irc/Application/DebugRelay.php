<?php

declare(strict_types=1);

namespace Dbots\Irc\Application;

use Dbots\Application\Port\Out\LinkTransportInterface;

use function preg_match;
use function sprintf;

/**
 * Mirrors service activity into a staff channel, like the original dBots
 * "debug" link did by dumping the S2S stream.
 *
 * It is deliberately narrower than the original: it reports the commands the
 * services execute and the mutations they push, and it never writes a credential
 * — a password or token in a channel is a leak with a long half-life.
 */
final readonly class DebugRelay
{
    public function __construct(
        private LinkTransportInterface $transport,
        private string $serviceUid,
        private string $channel,
    ) {
    }

    public function isEnabled(): bool
    {
        return '' !== $this->channel;
    }

    public function command(string $actor, string $command, string $detail = ''): void
    {
        $this->send(sprintf('CMD %s: %s%s', $actor, $command, '' === $detail ? '' : ' — ' . $detail));
    }

    public function mutation(string $line): void
    {
        $this->send('DB ' . $this->redact($line));
    }

    public function note(string $message): void
    {
        $this->send($message);
    }

    /** Removes anything that could carry a credential out of the log line. */
    public function redact(string $line): string
    {
        // N::<nick>::pass <value>, and any UDB value that looks like a hash.
        $line = (string) preg_replace('/pass\s+:\S+/i', 'pass :<redacted>', $line);
        $line = (string) preg_replace('/::pass\s+:\S+/i', '::pass :<redacted>', $line);
        $line = (string) preg_replace('/!s[A-Za-z0-9+\/=]{20,}/', '!s<redacted>', $line);

        return $line;
    }

    private function send(string $text): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->transport->writeLine(sprintf(':%s PRIVMSG %s :%s', $this->serviceUid, $this->channel, $text));
    }
}
