<?php

declare(strict_types=1);

namespace Dbots\Irc\Application;

use Dbots\Application\Port\Out\LinkTransportInterface;

use function in_array;
use function preg_match;
use function sprintf;
use function strtoupper;
use function date;

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

    /**
     * Mirrors one raw protocol line, the way the original debug link did.
     *
     * The original showed `(date - time) => <line>`; keeping the wire form is
     * the point — a summary teaches you nothing about what the services said.
     */
    public function line(string $line): void
    {
        $this->send(sprintf('(%s - %s) => %s', date('d/m/Y'), date('H:i'), $this->redact($line)));
    }

    public function note(string $message): void
    {
        $this->send($message);
    }

    /**
     * Commands whose arguments are a secret. Their line is mirrored with the
     * arguments hidden: the original dumped everything, but a password in a
     * channel outlives the session that typed it.
     */
    private const array SECRET_COMMANDS = ['REGISTER', 'REGISTRA', 'IDENTIFY', 'IDENTIFICA', 'DROP', 'ROOT', 'SET'];

    /** Removes anything that could carry a credential out of the log line. */
    public function redact(string $line): string
    {
        // A command carrying a password or a token.
        if (1 === preg_match('/^(.*PRIVMSG \S+ :)(\S+)(.*)$/i', $line, $matches)
            && in_array(strtoupper($matches[2]), self::SECRET_COMMANDS, true)) {
            return $matches[1] . $matches[2] . ' <argumentos ocultos>';
        }

        // N::<nick>::pass and any UDB value that looks like a credential.
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
