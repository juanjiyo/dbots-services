<?php

declare(strict_types=1);

namespace Dbots\Irc\Application;

use function date;
use function sprintf;
use function strtoupper;

/**
 * Renders a debug line the way the network's #debug channel looks today:
 *
 *   HH:MM  <tag> ¦ <subject> <text>
 *
 * with 14 for the clock, 12 for separators and subjects, and 02 for the tag.
 * The original dBots built these with raw mIRC codes; the codes here are the
 * same numbers, so a client that already styles that channel renders this too.
 */
final readonly class DebugFormatter
{
    private const string RESET = "\x0F";

    public function __construct(private string $tag = 'DeBuG') {}

    public function line(string $subject, string $text): string
    {
        return sprintf(
            "\x0314%s\x0312:\x0314%s%s \x0312\x0302%s\x0312 \u{00A6}%s \x0312%s%s %s",
            date('H'),
            date('i'),
            self::RESET,
            $this->tag,
            self::RESET,
            $subject,
            self::RESET,
            $text,
        );
    }

    /** A full sentence: clock and tag, then the text as given. */
    public function event(string $text): string
    {
        return sprintf(
            "\x0314%s\x0312:\x0314%s%s \x0312\x0302%s\x0312 \u{00A6}%s %s",
            date('H'),
            date('i'),
            self::RESET,
            $this->tag,
            self::RESET,
            $text,
        );
    }

    /** Interprets a UDB mutation as the human sentence the channel shows. */
    public function mutation(string $line): ?string
    {
        if (1 !== preg_match('/DB \* (INS|DEL) \S+ \d+ (\w)::(\S+) :(.*)$/', $line, $m)) {
            return null;
        }

        [, $operation, $block, $path, $value] = $m;
        $parts = explode('::', $path);
        $verb = 'INS' === $operation ? 'añade' : 'borra';

        if ('N' === $block && 2 === count($parts)) {
            [$nick, $key] = $parts;

            return match ($key) {
                'pass' => sprintf('NickServ registra o cambia la clave de %s', $nick),
                'forbid' => sprintf('NickServ %s la prohibición de %s', $verb, $nick),
                'suspend' => sprintf('NickServ %s la suspensión de %s', $verb, $nick),
                'swhois' => sprintf('NickServ %s el SWHOIS de %s', $verb, $nick),
                'vhost' => sprintf('NickServ %s el vhost de %s', $verb, $nick),
                default => sprintf('NickServ %s %s (%s)', $verb, $nick, $key),
            };
        }

        if ('C' === $block && 2 === count($parts)) {
            [$channel, $key] = $parts;

            return match ($key) {
                'founder' => sprintf('ChanServ fija el fundador de %s a %s', $channel, $value),
                'topic' => sprintf('ChanServ cambia el tema de %s', $channel),
                default => sprintf('ChanServ %s %s (%s)', $verb, $channel, $key),
            };
        }

        if ('C' === $block && 3 === count($parts) && 'access' === $parts[1]) {
            return sprintf('ChanServ %s acceso de %s a %s', $verb, $parts[2], $parts[0]);
        }

        if ('K' === $block) {
            return sprintf('OperServ %s una regla de spamfilter', $verb);
        }

        return sprintf('DB %s %s::%s', strtoupper($operation), $block, $path);
    }
}
