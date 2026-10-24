<?php

declare(strict_types=1);

namespace Dbots\Application;

use Dbots\Application\Link\LinkRunner;
use Dbots\Application\Port\Out\LinkTransportInterface;
use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Adapter\In\IrcMessageParser;
use Dbots\Irc\Adapter\Out\Irc\ServiceUidFormatter;
use Dbots\Irc\Application\DebugRelay;
use Dbots\Irc\Application\NetworkState;
use Dbots\ChanServ\Adapter\In\Irc\ChanServCommandHandler;
use Dbots\MemoServ\Adapter\In\Irc\MemoServCommandHandler;
use Dbots\NickServ\Adapter\In\Irc\NickServCommandHandler;
use Dbots\OperServ\Adapter\In\Irc\OperServCommandHandler;
use Dbots\Udb\UdbAuthority;

use function sprintf;
use function str_contains;

/**
 * Runs the daemon: links, introduces the service pseudo-clients and keeps the
 * network view in sync while routing commands.
 */
final readonly class Daemon
{
    public function __construct(
        private LinkRunner $runner,
        private LinkTransportInterface $transport,
        private IrcMessageParser $parser,
        private NetworkState $state,
        private NickServCommandHandler $nickServ,
        private ChanServCommandHandler $chanServ,
        private MemoServCommandHandler $memoServ,
        private OperServCommandHandler $operServ,
        private ServiceUidFormatter $uidFormatter,
        private string $sid,
        private string $serviceHost,
        private ?UdbAuthority $udb = null,
        private ?DebugRelay $debug = null,
        private string $debugChannel = '',
    ) {
    }

    public function handshakeState(): \Dbots\Domain\Link\LinkState
    {
        return $this->runner->handshake()->state();
    }

    public function run(int $seconds): void
    {
        $this->runner->connect(fn (string $line): null => $this->onLine($line));

        $this->introduceServices();
        $this->joinDebugChannel();

        $this->runner->pump($seconds, fn (string $line): null => $this->onLine($line));
    }

    public function stop(): void
    {
        $this->runner->close();
    }

    private function introduceServices(): void
    {
        $services = [
            ['NickServ', sprintf('%sA00001', $this->sid), 'Nickname Registration Services'],
            ['ChanServ', sprintf('%sA00002', $this->sid), 'Channel Registration Services'],
            ['MemoServ', sprintf('%sA00003', $this->sid), 'Memo Services'],
            ['OperServ', sprintf('%sA00004', $this->sid), 'Network Operations Services'],
        ];

        if (null !== $this->debug && $this->debug->isEnabled()) {
            $services[] = ['DebugServ', sprintf('%sA00005', $this->sid), 'Network Debug Relay'];
        }

        foreach ($services as [$nick, $uid, $realname]) {
            $this->transport->writeLine($this->uidFormatter->format(
                nick: $nick,
                uid: $uid,
                realname: $realname,
                ident: $nick,
                host: $this->serviceHost,
            ));
        }
    }

    /** The relay only speaks if it is actually sitting in the channel. */
    private function joinDebugChannel(): void
    {
        if (null === $this->debug || !$this->debug->isEnabled()) {
            return;
        }

        $uid = sprintf('%sA00005', $this->sid);
        $this->transport->writeLine(sprintf(':%s JOIN %s', $uid, $this->debugChannel));
        $this->transport->writeLine(sprintf(':%s MODE %s +o %s', $this->sid, $this->debugChannel, $uid));
    }

    private function onLine(string $line): null
    {
        $message = $this->parser->parse($line);

        if (null === $message) {
            return null;
        }

        if ('1' === getenv('DBOTS_DEBUG') && in_array($message->command, ['DB', 'PRIVMSG', 'ACCOUNT', 'UID', 'MODE', 'UMODE2'], true)) {
            fwrite(STDERR, "{$message->command}> {$line}\n");
        }

        match ($message->command) {
            'UID' => $this->onUserIntroduced($message),
            'NICK' => $this->onNickChange($message),
            'QUIT' => $this->onUserQuit($message),
            'DB' => $this->onDbFrame($message, $line),
            'PRIVMSG' => $this->onPrivmsg($message),
            'ACCOUNT' => $this->state->setAccount($this->resolveUid($message->source()), $message->params[0] ?? null),
            'UMODE2' => $this->state->applyModeChange($this->resolveUid($message->source()), $message->params[0] ?? ''),
            'MODE' => $this->onMode($message),
            default => null,
        };

        // The original debug link mirrored the commands the services received.
        if ('PRIVMSG' === $message->command) {
            $this->relayToDebug($line);
        }

        return null;
    }

    /** Maps one of our service UIDs (or nicknames) to its display name. */
    private function serviceName(?string $target): ?string
    {
        $known = [
            sprintf('%sA00001', $this->sid) => 'NickServ',
            sprintf('%sA00002', $this->sid) => 'ChanServ',
            sprintf('%sA00003', $this->sid) => 'MemoServ',
            sprintf('%sA00004', $this->sid) => 'OperServ',
        ];

        if (null === $target) {
            return null;
        }

        return $known[$target] ?? (in_array($target, $known, true) ? $target : null);
    }

    private function onDbFrame(IrcMessage $message, string $line): void
    {
        $this->udb?->handle($message);

        // A reconciliation storm would swamp the channel; live mutations are what
        // an operator wants to watch.
        if (str_contains($line, ' INS ') || str_contains($line, ' DEL ')) {
            $this->relayToDebug($line);
        }
    }

    /** Echoes one protocol line to the debug channel, like the original link did. */
    private function relayToDebug(string $line): void
    {
        if (null !== $this->debug && $this->debug->isEnabled()) {
            $this->debug->line($line);
        }
    }

    private function onUserIntroduced(IrcMessage $message): void
    {
        $nick = $message->params[0] ?? null;
        $uid = $message->params[5] ?? null;
        $modes = $message->params[7] ?? '';
        $host = $message->params[4] ?? null;

        if (null === $nick || null === $uid) {
            return;
        }

        // A client UID carries the user modes but not the account; identity is
        // derived from +r plus our own account store.
        $this->state->introduceUser($uid, $nick, null, ltrim($modes, '+'), $host);
    }

    /**
     * Sources arrive as a UID for server-originated frames and as a nick for
     * some user-originated ones, so both forms have to resolve to the UID.
     */
    private function resolveUid(?string $source): string
    {
        if (null === $source) {
            return '';
        }

        if (null !== $this->state->nickOf($source)) {
            return $source;
        }

        return $this->state->uidOf($source) ?? $source;
    }

    private function onMode(IrcMessage $message): void
    {
        $target = $message->params[0] ?? null;

        if (null === $target || str_starts_with($target, '#')) {
            return;
        }

        $this->state->applyModeChange($this->resolveUid($target), $message->params[1] ?? '');
    }

    private function onPrivmsg(IrcMessage $message): void
    {
        $this->nickServ->handle($message, $this->state);
        $this->chanServ->handle($message, $this->state);
        $this->memoServ->handle($message, $this->state);
        $this->operServ->handle($message, $this->state);


    }

    /**
     * Reports who ran which service command.
     *
     * Only the verb: the arguments may contain a password or a memo body, and a
     * debug channel is the last place those should appear.
     */
    private function relayCommand(IrcMessage $message): void
    {
        if (null === $this->debug || !$this->debug->isEnabled()) {
            return;
        }

        // The IRCd routes to the UID, not the nick, so translate back.
        $service = $this->serviceName($message->target());

        if (null === $service) {
            return;
        }

        $verb = strtoupper(strtok($message->text(), ' ') ?: '');
        $actor = $this->state->nickOf($message->source() ?? '') ?? 'unknown';

        if ('' !== $verb) {
            $this->debug->command($actor, $verb, $service);
        }
    }

    private function onNickChange(IrcMessage $message): void
    {
        $uid = $message->source();
        $nick = $message->params[0] ?? $message->trailing;

        if (null === $uid || null === $nick) {
            return;
        }

        $this->state->rename($uid, $nick);
    }

    private function onUserQuit(IrcMessage $message): void
    {
        $uid = $message->source();

        if (null !== $uid) {
            $this->state->remove($uid);
        }
    }
}
