<?php

declare(strict_types=1);

namespace Dbots\Application;

use Dbots\Application\Link\LinkRunner;
use Dbots\Application\Port\Out\LinkTransportInterface;
use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Adapter\In\IrcMessageParser;
use Dbots\Irc\Adapter\Out\Irc\ServiceUidFormatter;
use Dbots\Irc\Application\NetworkState;
use Dbots\NickServ\Adapter\In\Irc\NickServCommandHandler;

use function sprintf;

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
        private ServiceUidFormatter $uidFormatter,
        private string $sid,
        private string $serviceHost,
    ) {
    }

    public function handshakeState(): \Dbots\Domain\Link\LinkState
    {
        return $this->runner->handshake()->state();
    }

    public function run(int $seconds): void
    {
        $this->runner->connect(fn (string $line): null => $this->onLine($line));

        $this->introduceNickServ();

        $this->runner->pump($seconds, fn (string $line): null => $this->onLine($line));
    }

    public function stop(): void
    {
        $this->runner->close();
    }

    private function introduceNickServ(): void
    {
        $this->transport->writeLine($this->uidFormatter->format(
            nick: 'NickServ',
            uid: sprintf('%sA00001', $this->sid),
            realname: 'Nickname Registration Services',
            ident: 'NickServ',
            host: $this->serviceHost,
        ));
    }

    private function onLine(string $line): null
    {
        $message = $this->parser->parse($line);

        if (null === $message) {
            return null;
        }

        match ($message->command) {
            'UID' => $this->onUserIntroduced($message),
            'NICK' => $this->onNickChange($message),
            'QUIT' => $this->onUserQuit($message),
            'PRIVMSG' => $this->nickServ->handle($message, $this->state),
            default => null,
        };

        return null;
    }

    private function onUserIntroduced(IrcMessage $message): void
    {
        $nick = $message->params[0] ?? null;
        $uid = $message->params[5] ?? null;

        if (null === $nick || null === $uid) {
            return;
        }

        $this->state->introduceUser($uid, $nick);
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
