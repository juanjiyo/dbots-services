<?php

declare(strict_types=1);

namespace Dbots\ChanServ\Adapter\In\Irc;

use Dbots\ChanServ\Application\DropChannel\DropChannelHandler;
use Dbots\ChanServ\Application\DropChannel\DropChannelResult;
use Dbots\ChanServ\Application\Port\Out\ChannelProjectionInterface;
use Dbots\ChanServ\Application\Port\Out\ChannelRepositoryInterface;
use Dbots\ChanServ\Application\RegisterChannel\RegisterChannelHandler;
use Dbots\ChanServ\Application\RegisterChannel\RegisterChannelResult;
use Dbots\Domain\Channel\ChannelName;
use Dbots\Domain\Account\Nick;
use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\NetworkState;
use Dbots\NickServ\Application\Port\Out\AccountRepositoryInterface;
use Dbots\NickServ\Application\Port\Out\ReplyInterface;
use InvalidArgumentException;

use function array_filter;
use function array_shift;
use function array_values;
use function explode;
use function sprintf;
use function strcasecmp;
use function strtoupper;

final readonly class ChanServCommandHandler
{
    public function __construct(
        private RegisterChannelHandler $register,
        private DropChannelHandler $drop,
        private ChannelRepositoryInterface $channels,
        private ChannelProjectionInterface $projection,
        private AccountRepositoryInterface $accounts,
        private ReplyInterface $reply,
        private string $serviceNick,
        private string $serviceUid,
    ) {
    }

    public function handle(IrcMessage $message, NetworkState $state): void
    {
        if ('PRIVMSG' !== $message->command) {
            return;
        }

        $target = $message->target();

        if (null === $target || !$this->isAddressedToMe($target)) {
            return;
        }

        $sender = $message->source();

        if (null === $sender) {
            return;
        }

        $parts = $this->tokenise($message->text());

        if ([] === $parts) {
            return;
        }

        $command = strtoupper((string) array_shift($parts));
        $account = $this->accountOf($sender, $state);

        match ($command) {
            'REGISTER', 'REGISTRA' => $this->onRegister($sender, $account, $parts),
            'DROP' => $this->onDrop($sender, $account, $parts),
            'INFO' => $this->onInfo($sender, $parts),
            'HELP', 'AYUDA' => $this->onHelp($sender),
            default => $this->reply->reply($sender, 'Unknown command. Use HELP to list the available commands.'),
        };
    }

    /** @param list<string> $args */
    private function onRegister(string $uid, ?string $account, array $args): void
    {
        $channel = $args[0] ?? null;

        if (null === $channel) {
            $this->reply->reply($uid, 'Syntax: REGISTER <#channel>');

            return;
        }

        $result = ($this->register)($channel, $account);

        if (RegisterChannelResult::Registered === $result) {
            $this->projection->publishRegistered($channel);
        }

        match ($result) {
            RegisterChannelResult::Registered => $this->reply->reply($uid, sprintf('%s is now registered to you.', $channel)),
            RegisterChannelResult::AlreadyRegistered => $this->reply->reply($uid, sprintf('%s is already registered.', $channel)),
            RegisterChannelResult::InvalidChannel => $this->reply->reply($uid, 'That channel name cannot be registered.'),
            RegisterChannelResult::NotIdentified => $this->reply->reply($uid, 'You must be identified to register a channel.'),
        };
    }

    /** @param list<string> $args */
    private function onDrop(string $uid, ?string $account, array $args): void
    {
        $channel = $args[0] ?? null;

        if (null === $channel) {
            $this->reply->reply($uid, 'Syntax: DROP <#channel>');

            return;
        }

        $result = ($this->drop)($channel, $account);

        if (DropChannelResult::Dropped === $result) {
            $this->projection->publishDropped($channel);
        }

        match ($result) {
            DropChannelResult::Dropped => $this->reply->reply($uid, sprintf('%s has been dropped.', $channel)),
            DropChannelResult::NotRegistered => $this->reply->reply($uid, sprintf('%s is not registered.', $channel)),
            DropChannelResult::NotFounder => $this->reply->reply($uid, 'Only the founder can drop a channel.'),
            DropChannelResult::InvalidChannel => $this->reply->reply($uid, 'That channel name cannot be registered.'),
        };
    }

    /** @param list<string> $args */
    private function onInfo(string $uid, array $args): void
    {
        $channel = $args[0] ?? null;

        if (null === $channel) {
            $this->reply->reply($uid, 'Syntax: INFO <#channel>');

            return;
        }

        try {
            $registered = $this->channels->find(new ChannelName($channel));
        } catch (InvalidArgumentException) {
            $this->reply->reply($uid, 'That channel name cannot be registered.');

            return;
        }

        if (null === $registered) {
            $this->reply->reply($uid, sprintf('%s is not registered.', $channel));

            return;
        }

        $this->reply->reply($uid, sprintf(
            '%s is registered to %s since %s.',
            $registered->name->value,
            $registered->founder,
            $registered->createdAt->format('Y-m-d'),
        ));
    }

    private function onHelp(string $uid): void
    {
        $this->reply->reply($uid, 'Commands: REGISTER <#channel>, DROP <#channel>, INFO <#channel>.');
    }

    /**
     * The sender's account, derived from +r and our own account store: a client
     * UID carries the modes but never the account name.
     */
    private function accountOf(string $uid, NetworkState $state): ?string
    {
        $nick = $state->nickOf($uid);

        if (null === $nick || !$state->hasMode($uid, 'r')) {
            return null;
        }

        try {
            return $this->accounts->findByNick(new Nick($nick))?->nick->value;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function isAddressedToMe(string $target): bool
    {
        return 0 === strcasecmp($target, $this->serviceNick) || $target === $this->serviceUid;
    }

    /** @return list<string> */
    private function tokenise(string $text): array
    {
        return array_values(array_filter(
            explode(' ', $text),
            static fn (string $token): bool => '' !== $token,
        ));
    }
}
