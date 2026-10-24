<?php

declare(strict_types=1);

namespace Dbots\Irc\Application;

use Dbots\Application\Port\Out\LinkTransportInterface;

use function in_array;
use function preg_match;
use function sprintf;
use function strtoupper;

/**
 * Mirrors service activity into a staff channel using the format that channel
 * already uses: the clock, a tag, the subject and a human sentence.
 *
 * It never writes a credential — a password or token in a channel is a leak with
 * a long half-life.
 */
final readonly class DebugRelay
{
    private readonly DebugFormatter $formatter;

    public function __construct(
        private LinkTransportInterface $transport,
        private string $serviceUid,
        private string $channel,
    ) {
        $this->formatter = new DebugFormatter();
    }

    public function isEnabled(): bool
    {
        return '' !== $this->channel;
    }

    /** Mirrors a network event: the sentence already carries the subject. */
    public function event(string $text): void
    {
        $this->send($this->formatter->event($text));
    }

    /** Mirrors a service command as an event, not as a raw frame. */
    public function command(string $actor, string $verb, string $service, string $arguments = ''): void
    {
        if ('' !== $arguments && !in_array(strtoupper($verb), self::SECRET_COMMANDS, true)) {
            $this->send($this->formatter->line($actor, sprintf('%s %s', $verb, $this->redact($arguments))));

            return;
        }

        $this->send($this->formatter->line($actor, sprintf('%s en %s', $verb, $service)));
    }

    /** Mirrors a UDB mutation, translated when we understand it. */
    public function mutation(string $line): void
    {
        $text = $this->formatter->mutation($line) ?? $this->redact($line);

        $this->send($this->formatter->line('DB', $text));
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
