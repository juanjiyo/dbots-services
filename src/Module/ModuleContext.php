<?php

declare(strict_types=1);

namespace Dbots\Module;

use Closure;
use Dbots\Application\Port\Out\LinkTransportInterface;
use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\OperServ\Application\Port\Out\EnforcementInterface;
use PDO;

use function sprintf;
use function strcasecmp;

/**
 * What a module gets from the daemon: its own identity and a way to speak.
 *
 * Sending a line is deliberately raw — a module is trusted code, not a sandbox.
 */
final readonly class ModuleContext
{
    /**
     * @param Closure(int, Closure): void $scheduler Callback that schedules a
     *                                              delayed closure after N seconds.
     */
    public function __construct(
        public string $uid,
        public string $sid,
        public LinkTransportInterface $transport,
        public EnforcementInterface $enforcement,
        public PDO $pdo,
        private Closure $scheduler,
    ) {
    }

    /** Schedules a callback to run after the given number of seconds. */
    public function schedule(int $seconds, Closure $callback): void
    {
        ($this->scheduler)($seconds, $callback);
    }

    /** A NOTICE from the module's own nick, which is how services answer. */
    public function reply(string $targetUid, string $text): void
    {
        $this->transport->writeLine(sprintf(':%s NOTICE %s :%s', $this->uid, $targetUid, $text));
    }

    public function line(string $line): void
    {
        $this->transport->writeLine($line);
    }

    /**
     * Whether the message was sent to this module. The IRCd addresses a service
     * by UID, not by nick, so both have to be accepted.
     */
    public function isAddressedTo(IrcMessage $message, string $nick): bool
    {
        $target = $message->target();

        if (null === $target) {
            return false;
        }

        return $target === $this->uid || 0 === strcasecmp($target, $nick);
    }
}
