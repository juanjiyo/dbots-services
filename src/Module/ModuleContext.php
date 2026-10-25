<?php

declare(strict_types=1);

namespace Dbots\Module;

use Dbots\Application\Port\Out\LinkTransportInterface;
use Dbots\Irc\Adapter\In\IrcMessage;

use function sprintf;
use function strcasecmp;

/**
 * What a module gets from the daemon: its own identity and a way to speak.
 *
 * Sending a line is deliberately raw — a module is trusted code, not a sandbox.
 */
final readonly class ModuleContext
{
    public function __construct(
        public string $uid,
        public string $sid,
        public LinkTransportInterface $transport,
    ) {
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
