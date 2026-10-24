<?php

declare(strict_types=1);

namespace Dbots\ChanServ\Adapter\In\Irc;

use Dbots\ChanServ\Application\DropChannel\DropChannelHandler;
use Dbots\ChanServ\Application\DropChannel\DropChannelResult;
use Dbots\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use Dbots\ChanServ\Application\Port\Out\ChannelProjectionInterface;
use Dbots\ChanServ\Application\Port\Out\ChannelRepositoryInterface;
use Dbots\ChanServ\Application\RegisterChannel\RegisterChannelHandler;
use Dbots\ChanServ\Application\RegisterChannel\RegisterChannelResult;
use Dbots\Domain\Account\Nick;
use Dbots\Domain\Channel\Channel;
use Dbots\Domain\Channel\ChannelName;
use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\NetworkState;
use Dbots\Irc\Application\AccountResolver;
use Dbots\NickServ\Application\Port\Out\ReplyInterface;
use InvalidArgumentException;

use function array_filter;
use function array_shift;
use function array_values;
use function explode;
use function array_slice;
use function implode;
use function sprintf;
use function strcasecmp;
use function strtoupper;

final readonly class ChanServCommandHandler
{
    public function __construct(
        private RegisterChannelHandler $register,
        private DropChannelHandler $drop,
        private ChannelRepositoryInterface $channels,
        private ChannelAccessRepositoryInterface $access,
        private ChannelProjectionInterface $projection,
        private AccountResolver $accounts,
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
        $account = $this->accounts->resolve($sender, $state);

        match ($command) {
            'REGISTER', 'REGISTRA' => $this->onRegister($sender, $account, $parts),
            'DROP' => $this->onDrop($sender, $account, $parts),
            'INFO' => $this->onInfo($sender, $parts),
            'ACCESS' => $this->onAccess($sender, $account, $parts),
            'TOPIC' => $this->onTopic($sender, $account, $parts),
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

    /** @param list<string> $args */
    private function onAccess(string $uid, ?string $account, array $args): void
    {
        $action = strtoupper((string) ($args[0] ?? ''));
        $channel = (string) ($args[1] ?? '');
        $nick = (string) ($args[2] ?? '');

        if ('' === $channel) {
            $this->reply->reply($uid, 'Syntax: ACCESS LIST|ADD|DEL <#channel> [nick]');

            return;
        }

        $registered = $this->ownedChannel($uid, $account, $channel);

        if (null === $registered) {
            return;
        }

        match ($action) {
            'LIST' => $this->onAccessList($uid, $registered),
            'ADD' => $this->onAccessAdd($uid, $registered, $nick),
            'DEL', 'DELETE' => $this->onAccessDel($uid, $registered, $nick),
            default => $this->reply->reply($uid, 'Syntax: ACCESS LIST|ADD|DEL <#channel> [nick]'),
        };
    }

    private function onAccessList(string $uid, Channel $channel): void
    {
        $entries = $this->access->forChannel($channel->name->value);

        if ([] === $entries) {
            $this->reply->reply($uid, sprintf('%s has no access list entries.', $channel->name->value));

            return;
        }

        $this->reply->reply($uid, sprintf('Access list for %s: %s.', $channel->name->value, implode(', ', $entries)));
    }

    private function onAccessAdd(string $uid, Channel $channel, string $nick): void
    {
        if ('' === $nick) {
            $this->reply->reply($uid, 'Syntax: ACCESS ADD <#channel> <nick>');

            return;
        }

        try {
            $account = (new Nick($nick))->value;
        } catch (InvalidArgumentException) {
            $this->reply->reply($uid, 'That nickname cannot hold access.');

            return;
        }

        $this->access->add($channel->name->value, $account);
        $this->projection->publishAccess($channel->name->value, $account);
        $this->reply->reply($uid, sprintf('%s now has access to %s.', $account, $channel->name->value));
    }

    private function onAccessDel(string $uid, Channel $channel, string $nick): void
    {
        if ('' === $nick) {
            $this->reply->reply($uid, 'Syntax: ACCESS DEL <#channel> <nick>');

            return;
        }

        $removed = $this->access->remove($channel->name->value, $nick);

        if ($removed) {
            $this->projection->removeAccess($channel->name->value, $nick);
        }

        $this->reply->reply($uid, $removed
            ? sprintf('%s no longer has access to %s.', $nick, $channel->name->value)
            : sprintf('%s is not on the access list of %s.', $nick, $channel->name->value));
    }

    /** @param list<string> $args */
    private function onTopic(string $uid, ?string $account, array $args): void
    {
        $channel = (string) ($args[0] ?? '');

        if ('' === $channel) {
            $this->reply->reply($uid, 'Syntax: TOPIC <#channel> <text>');

            return;
        }

        $registered = $this->ownedChannel($uid, $account, $channel);

        if (null === $registered) {
            return;
        }

        $topic = implode(' ', array_slice($args, 1));
        $this->channels->updateTopic(new Channel($registered->name, $registered->founder, $registered->createdAt, $topic));
        $this->projection->publishTopic($registered->name->value);

        $this->reply->reply($uid, sprintf('Topic for %s updated.', $registered->name->value));
    }

    /** Returns the channel only when the sender is its founder. */
    private function ownedChannel(string $uid, ?string $account, string $channel): ?Channel
    {
        try {
            $registered = $this->channels->find(new ChannelName($channel));
        } catch (InvalidArgumentException) {
            $this->reply->reply($uid, 'That channel name cannot be registered.');

            return null;
        }

        if (null === $registered) {
            $this->reply->reply($uid, sprintf('%s is not registered.', $channel));

            return null;
        }

        if (null === $account || !$registered->isFoundedBy($account)) {
            $this->reply->reply($uid, 'Only the founder can do that.');

            return null;
        }

        return $registered;
    }

    private function onHelp(string $uid): void
    {
        $this->reply->reply($uid, 'Commands: REGISTER <#channel>, DROP <#channel>, INFO <#channel>, ACCESS LIST|ADD|DEL <#channel> [nick], TOPIC <#channel> <text>.');
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
