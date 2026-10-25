<?php

declare(strict_types=1);

namespace Dbots\Irc\Application;

use Dbots\Irc\Adapter\In\IrcMessage;

use function count;
use function explode;
use function implode;
use function ltrim;
use function preg_replace;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function trim;

/**
 * Turns one protocol frame into the sentence the debug channel shows.
 *
 * Port of the branch table in the original `de.mrc`: the same events, the same
 * wording, and the same colour codes (12 for people and subjects, 4 for
 * anything destructive, 6 for asides).
 */
final class DebugEvents
{
    private const string R = "\x0F";

    /** @var (callable(string): ?string)|null */
    private static $resolve = null;

    /**
     * @param callable(string): ?string $resolve maps a UID to its nickname, so the
     *        channel shows people instead of UIDs — the original showed nicks.
     */
    public static function translate(IrcMessage $message, ?callable $resolve = null): ?string
    {
        self::$resolve = $resolve;
        $source = self::nick($message);

        return match ($message->command) {
            'UID' => self::uid($message),
            'NICK' => null === $source ? null : self::nickChange($message),
            'JOIN' => self::join($source, $message),
            'SJOIN' => self::sjoin($message),
            'PART' => self::part($source, $message),
            'TOPIC' => self::topic($source, $message),
            'QUIT' => self::quit($source, $message),
            'KICK' => self::kick($source, $message),
            'KILL' => self::kill($source, $message),
            'MODE' => self::mode($source, $message),
            'UMODE2' => self::umode($source, $message),
            'SVS2MODE' => self::forcedModes($message),
            'SWHOIS' => self::swhois($source, $message),
            'SETNAME', 'CHGNAME' => self::setname($source, $message),
            'CHGHOST', 'CHGIDENT', 'SETHOST' => self::hostChange($source, $message),
            'AWAY' => self::away($source, $message),
            'NOTICE' => self::notice($message),
            'CHATOPS' => self::broadcast($source, $message, 'CHATOPS', 'todos los OPERs'),
            'ADCHAT' => self::broadcast($source, $message, 'ADCHAT', 'todos los ADMINs'),
            'NACHAT' => self::broadcast($source, $message, 'NACHAT', 'todos los NET-ADMINs'),
            'SENDSNO' => self::sendsno($source, $message),
            'SERVER' => self::server($message),
            'SQUIT' => self::squit($message),
            default => null,
        };
    }

    private static function paint(int $color, string $text): string
    {
        return sprintf("\x03%02d%s%s", $color, $text, self::R);
    }

    private static function nick(IrcMessage $message): string
    {
        return self::display($message->prefix ?? '');
    }

    /** UID to nickname when we know it; the raw prefix otherwise. */
    private static function display(string $prefix): string
    {
        if ('' === $prefix || null === self::$resolve) {
            return $prefix;
        }

        $resolver = self::$resolve;

        return $resolver($prefix) ?? $prefix;
    }

    private static function uid(IrcMessage $message): ?string
    {
        $nick = $message->params[0] ?? null;
        $ident = $message->params[3] ?? null;
        $host = $message->params[4] ?? null;
        $modes = $message->params[7] ?? '';

        if (null === $nick || null === $ident || null === $host) {
            return null;
        }

        $line = sprintf(
            '%s: %s %s %s %s %s%s',
            self::paint(3, 'Conecta'),
            self::paint(12, 'NICK:') . ' ' . $nick,
            self::paint(12, 'IDENT:') . ' ' . $ident,
            self::paint(12, 'IP/HOST:') . ' ' . $host,
            self::paint(12, 'REALNAME:') . ' ' . ($message->trailing ?? ''),
            self::paint(12, 'SERVIDOR:') . ' ' . self::display((string) ($message->prefix ?? '')),
            '' === $modes ? '' : ' ' . self::paint(12, 'MODOS:') . ' ' . $modes,
        );

        return $line;
    }

    private static function nickChange(IrcMessage $message): string
    {
        return sprintf('%s cambia de nick a %s', self::paint(12, self::nick($message)), self::paint(12, (string) ($message->params[0] ?? '')));
    }

    private static function join(string $source, IrcMessage $message): ?string
    {
        $channel = $message->params[0] ?? null;

        return null === $channel ? null : sprintf(
            '%s entra al canal %s',
            self::paint(12, $source),
            self::paint(12, $channel),
        );
    }

    /** Servers announce joins in bulk; expand it so the channel reads like a JOIN. */
    private static function sjoin(IrcMessage $message): ?string
    {
        $channel = $message->params[1] ?? null;
        $members = (string) ($message->trailing ?? '');

        if (null === $channel || '' === $members) {
            return null;
        }

        $names = [];

        foreach (explode(' ', $members) as $member) {
            // A defensive skip: some servers put the mode block in the same field.
            if (str_starts_with($member, '+')) {
                continue;
            }

            $member = ltrim($member, '+%@&~');
            $member = preg_replace('/^[^:]*:/', '', $member) ?? $member;

            if ('' !== $member) {
                $names[] = self::display($member);
            }
        }

        return match (count($names)) {
            0 => null,
            1 => sprintf('%s entra al canal %s', self::paint(12, $names[0]), self::paint(12, $channel)),
            default => sprintf('%s entran al canal %s', self::paint(12, implode(', ', $names)), self::paint(12, $channel)),
        };
    }

    private static function part(string $source, IrcMessage $message): ?string
    {
        $channel = $message->params[0] ?? null;

        if (null === $channel) {
            return null;
        }

        $reason = (string) ($message->trailing ?? '');

        return sprintf(
            '%s sale del canal %s%s',
            self::paint(12, $source),
            self::paint(12, $channel),
            '' === $reason ? '' : ' con el mensaje: ' . $reason,
        );
    }

    private static function topic(string $source, IrcMessage $message): ?string
    {
        $channel = $message->params[0] ?? null;

        if (null === $channel) {
            return null;
        }

        $topic = (string) ($message->trailing ?? '');

        return '' === $topic
            ? sprintf('%s quita el topic de %s', self::paint(12, $source), self::paint(12, $channel))
            : sprintf('%s cambia el topic de %s por: %s', self::paint(12, $source), self::paint(12, $channel), $topic);
    }

    private static function quit(string $source, IrcMessage $message): string
    {
        return sprintf(
            '%s %s %s %s(%s)',
            self::paint(4, 'Desconecta:'),
            self::paint(12, 'NICK:'),
            '' === $source ? 'desconocido' : $source,
            self::paint(12, ''),
            (string) ($message->trailing ?? 'sin motivo'),
        );
    }

    private static function kick(string $source, IrcMessage $message): ?string
    {
        $channel = $message->params[0] ?? null;
        $victim = $message->params[1] ?? null;

        if (null === $channel || null === $victim) {
            return null;
        }

        $reason = (string) ($message->trailing ?? '');

        return sprintf(
            '%s expulsa a %s del canal %s%s',
            self::paint(12, $source),
            self::paint(12, $victim),
            self::paint(12, $channel),
            '' === $reason ? '' : ', motivo: ' . $reason,
        );
    }

    private static function kill(string $source, IrcMessage $message): ?string
    {
        $victim = $message->params[0] ?? null;

        if (null === $victim) {
            return null;
        }

        $reason = (string) ($message->trailing ?? '');

        return sprintf(
            '%s %sea a %s: %s',
            self::paint(12, $source),
            self::paint(4, 'KILL'),
            self::paint(12, $victim),
            $reason,
        );
    }

    private static function mode(string $source, IrcMessage $message): ?string
    {
        $target = $message->params[0] ?? null;
        $modes = $message->params[1] ?? null;

        if (null === $target || null === $modes) {
            return null;
        }

        if (str_starts_with($target, '#')) {
            return sprintf(
                '%s cambia modos de %s a %s',
                self::paint(12, $source),
                self::paint(12, $target),
                self::paint(12, $modes),
            );
        }

        return sprintf('%s cambia modos a: %s', self::paint(12, self::nick($message) ?: $target), self::paint(12, $modes));
    }

    private static function umode(string $source, IrcMessage $message): ?string
    {
        $modes = $message->params[0] ?? null;

        return null === $modes ? null : sprintf('%s cambia modos a %s', self::paint(12, $source), self::paint(12, ltrim($modes, '+')));
    }

    private static function forcedModes(IrcMessage $message): ?string
    {
        $target = $message->params[0] ?? null;
        $modes = $message->params[1] ?? null;

        return null === $target || null === $modes ? null : sprintf(
            '%s en %s: %s',
            self::paint(4, 'Modos forzados'),
            self::paint(12, $target),
            self::paint(12, $modes),
        );
    }

    private static function swhois(string $source, IrcMessage $message): ?string
    {
        $target = $message->params[0] ?? null;

        if (null === $target) {
            return null;
        }

        $text = trim((string) ($message->trailing ?? ''));

        return '' === $text
            ? sprintf('%s %s el %s a %s', self::paint(12, $source), self::paint(4, 'quita'), self::paint(12, 'SWHOIS'), self::paint(12, $target))
            : sprintf('%s pone un %s a %s: %s', self::paint(12, $source), self::paint(12, 'SWHOIS'), self::paint(12, $target), $text);
    }

    private static function setname(string $source, IrcMessage $message): ?string
    {
        $text = trim((string) ($message->trailing ?? ''));

        return '' === $text ? null : sprintf('%s usa %s: %s', self::paint(12, $source), self::paint(12, $message->command), $text);
    }

    private static function hostChange(string $source, IrcMessage $message): ?string
    {
        $target = $message->params[0] ?? null;
        $value = $message->params[1] ?? null;

        return null === $target || null === $value ? null : sprintf(
            '%s usa %s sobre %s: %s',
            self::paint(12, $source),
            self::paint(12, $message->command),
            self::paint(12, $target),
            $value,
        );
    }

    private static function away(string $source, IrcMessage $message): string
    {
        // AWAY carries its text in the trailing parameter; params[0] is empty.
        $text = trim((string) ($message->params[0] ?? $message->trailing ?? ''));

        return '' === $text
            ? sprintf('%s deja de estar ausente.', self::paint(12, $source))
            : sprintf('%s se ausenta, mensaje: %s', self::paint(12, $source), $text);
    }

    /** The original echoed notices verbatim, except the AUTH handshake. */
    private static function notice(IrcMessage $message): ?string
    {
        $target = $message->params[0] ?? '';

        if ('AUTH' === $target) {
            return null;
        }

        return trim(sprintf('%s %s', self::display($message->prefix ?? ''), (string) ($message->trailing ?? '')));
    }

    private static function broadcast(string $source, IrcMessage $message, string $command, string $audience): string
    {
        return sprintf(
            '%s envia un %s %s(%s)%s con el mensaje: %s',
            self::paint(12, $source),
            self::paint(12, $command),
            self::paint(6, ''),
            sprintf('Mensaje a %s', $audience),
            self::paint(6, ''),
            (string) ($message->trailing ?? ''),
        );
    }

    /**
     * Oper notifications: an oper-up, a failed attempt, a temporary shun and a
     * spam hit. The original keyed on the snomask letter in the first parameter.
     */
    private static function sendsno(string $source, IrcMessage $message): ?string
    {
        $mask = (string) ($message->params[0] ?? '');
        $text = trim((string) ($message->trailing ?? $message->params[1] ?? ''));

        if ('' === $text) {
            return null;
        }

        return match (true) {
            str_contains($mask, 'o') && str_contains($text, 'intento') => sprintf('%s %sintentó identificarse como IRCop...', self::paint(4, ''), $text),
            str_contains($mask, 'o') => sprintf('%s es ahora %s', self::paint(12, $source), self::paint(12, $text)),
            str_contains($mask, 'S') => sprintf('%s detectado: %s', self::paint(4, 'SPAM'), $text),
            default => sprintf('%s %s', self::paint(12, $source), $text),
        };
    }

    private static function server(IrcMessage $message): ?string
    {
        $name = $message->params[0] ?? null;

        return null === $name ? null : sprintf('Servidor %s (%s) entra en la red.', self::paint(12, $name), self::paint(6, (string) ($message->trailing ?? '')));
    }

    private static function squit(IrcMessage $message): ?string
    {
        $name = $message->params[0] ?? null;

        return null === $name ? null : sprintf('Servidor %s sale de la red: %s', self::paint(12, $name), (string) ($message->trailing ?? ''));
    }
}
