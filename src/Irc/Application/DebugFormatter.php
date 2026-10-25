<?php

declare(strict_types=1);

namespace Dbots\Irc\Application;

use function sprintf;
use function strtoupper;

/**
 * Formats the body of one debug line.
 *
 * No prefix and no clock: the `<DeBuG>` the channel shows is the pseudo-client's
 * nickname and the timestamp is the client's own. This only paints the text,
 * with 12 for names and subjects and 4 for anything destructive, the same codes
 * the original mIRC scripts used.
 */
final readonly class DebugFormatter
{
    private const string RESET = "\x0F";

    public function __construct(private readonly string $tag = 'DeBuG') {}

    public function line(string $subject, string $text): string
    {
        return sprintf('%s %s', $this->subject($subject), $text);
    }

    /** Colour 12, the same one the original used for names and subjects. */
    private function subject(string $text): string
    {
        return "\x0312" . $text . self::RESET;
    }

    public function event(string $text): string
    {
        return $text;
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
                'pass' => sprintf('NiCK registra o cambia la clave de %s', $nick),
                'forbid' => sprintf('NiCK %s la prohibición de %s', $verb, $nick),
                'suspend' => sprintf('NiCK %s la suspensión de %s', $verb, $nick),
                'swhois' => sprintf('NiCK %s el SWHOIS de %s', $verb, $nick),
                'vhost' => sprintf('NiCK %s el vhost de %s', $verb, $nick),
                default => sprintf('NiCK %s %s (%s)', $verb, $nick, $key),
            };
        }

        if ('C' === $block && 2 === count($parts)) {
            [$channel, $key] = $parts;

            return match ($key) {
                'founder' => sprintf('CHaN fija el fundador de %s a %s', $channel, $value),
                'topic' => sprintf('CHaN cambia el tema de %s', $channel),
                default => sprintf('CHaN %s %s (%s)', $verb, $channel, $key),
            };
        }

        if ('C' === $block && 3 === count($parts) && 'access' === $parts[1]) {
            return sprintf('CHaN %s acceso de %s a %s', $verb, $parts[2], $parts[0]);
        }

        if ('K' === $block) {
            return sprintf('OPeR %s una regla de spamfilter', $verb);
        }

        return sprintf('DB %s %s::%s', strtoupper($operation), $block, $path);
    }
}
