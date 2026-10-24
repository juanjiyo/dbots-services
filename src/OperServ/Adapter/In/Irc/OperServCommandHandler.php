<?php

declare(strict_types=1);

namespace Dbots\OperServ\Adapter\In\Irc;

use Dbots\ChanServ\Application\Port\Out\ChannelProjectionInterface;
use Dbots\ChanServ\Application\Port\Out\ChannelRepositoryInterface;
use Dbots\Domain\Account\Nick;
use Dbots\Domain\Channel\Channel;
use Dbots\Domain\Channel\ChannelName;
use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\NetworkState;
use Dbots\NickServ\Application\Port\Out\AccountProjectionInterface;
use Dbots\NickServ\Application\Port\Out\AccountRepositoryInterface;
use Dbots\NickServ\Application\Port\Out\ReplyInterface;
use Dbots\OperServ\Application\Duration;
use InvalidArgumentException;
use Dbots\OperServ\Application\Port\Out\EnforcementInterface;

use function array_filter;
use function array_shift;
use function array_slice;
use function array_values;
use function explode;
use function implode;
use function sprintf;
use function strcasecmp;
use function strpos;
use function random_int;
use function strtoupper;
use function substr;

/**
 * IRC operator commands.
 *
 * Every mutating command requires the sender to carry user mode +o; the daemon
 * never infers operator status from a nickname or from who is registered.
 */
final readonly class OperServCommandHandler
{
    public function __construct(
        private EnforcementInterface $enforcement,
        private AccountRepositoryInterface $accounts,
        private AccountProjectionInterface $accountProjection,
        private ChannelRepositoryInterface $channels,
        private ChannelProjectionInterface $channelProjection,
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

        match ($command) {
            'KILL' => $this->onKill($sender, $parts, $state),
            'KICK' => $this->onKick($sender, $parts, $state),
            'OP' => $this->onOp($sender, $parts, $state, '+o'),
            'DEOP' => $this->onOp($sender, $parts, $state, '-o'),
            'MODE' => $this->onMode($sender, $parts, $state),
            'GLINE' => $this->onGline($sender, $parts, $state),
            'UNGLINE' => $this->onUngline($sender, $parts, $state),
            'RENAME' => $this->onRename($sender, $parts, $state),
            'DROPNDB' => $this->onDropNdb($sender, $parts, $state),
            'APODERA' => $this->onApodera($sender, $parts, $state),
            'STATS' => $this->onStats($sender, $state),
            'IRCOPS' => $this->onIrcops($sender, $state),
            'HELP', 'AYUDA' => $this->onHelp($sender),
            default => $this->reply->reply($sender, 'Unknown command. Use HELP to list the available commands.'),
        };
    }

    /** @param list<string> $args */
    private function onKill(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireOper($sender, $state)) {
            return;
        }

        $nick = array_shift($args);

        if (null === $nick) {
            $this->reply->reply($sender, 'Syntax: KILL <nick> [reason]');

            return;
        }

        $uid = $state->uidOf($nick);

        if (null === $uid) {
            $this->reply->reply($sender, sprintf('%s is not online.', $nick));

            return;
        }

        $reason = [] === $args ? 'Killed by OperServ' : implode(' ', array_values($args));

        $this->enforcement->kill($uid, $reason);
        $this->reply->reply($sender, sprintf('%s has been killed.', $nick));
    }

    /** @param list<string> $args */
    private function onKick(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireOper($sender, $state)) {
            return;
        }

        $channel = $args[0] ?? null;
        $nick = $args[1] ?? null;

        if (null === $channel || null === $nick) {
            $this->reply->reply($sender, 'Syntax: KICK <#channel> <nick> [reason]');

            return;
        }

        $uid = $state->uidOf($nick);

        if (null === $uid) {
            $this->reply->reply($sender, sprintf('%s is not online.', $nick));

            return;
        }

        $reason = implode(' ', array_values(array_slice($args, 2)));

        $this->enforcement->kick($channel, $uid, '' === $reason ? 'Kicked by OperServ' : $reason);
        $this->reply->reply($sender, sprintf('%s has been kicked from %s.', $nick, $channel));
    }

    /** @param list<string> $args */
    private function onOp(string $sender, array $args, NetworkState $state, string $mode): void
    {
        if (!$this->requireOper($sender, $state)) {
            return;
        }

        $channel = array_shift($args);
        $nick = array_shift($args);

        if (null === $channel || null === $nick) {
            $this->reply->reply($sender, 'Syntax: ' . ('+o' === $mode ? 'OP' : 'DEOP') . ' <#channel> <nick>');

            return;
        }

        $this->enforcement->channelMode($channel, $mode, $nick);
        $this->reply->reply($sender, sprintf('%s%s on %s.', '+o' === $mode ? 'Opped ' : 'Deopped ', $nick, $channel));
    }

    /** @param list<string> $args */
    private function onMode(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireOper($sender, $state)) {
            return;
        }

        $channel = array_shift($args);
        $modes = array_shift($args);

        if (null === $channel || null === $modes) {
            $this->reply->reply($sender, 'Syntax: MODE <#channel> <modes> [argument]');

            return;
        }

        $this->enforcement->channelMode($channel, $modes, $args[0] ?? null);
        $this->reply->reply($sender, sprintf('Mode %s applied to %s.', $modes, $channel));
    }

    /** @param list<string> $args */
    private function onGline(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireOper($sender, $state)) {
            return;
        }

        $mask = array_shift($args);
        $durationValue = array_shift($args);

        if (null === $mask || null === $durationValue) {
            $this->reply->reply($sender, 'Syntax: GLINE <user@host> <duration> [reason]');

            return;
        }

        $duration = Duration::parse($durationValue);

        if (null === $duration) {
            $this->reply->reply($sender, 'Invalid duration. Use minutes, or a suffix: 30m, 2h, 1d, 0 for permanent.');

            return;
        }

        [$user, $host] = $this->splitMask($mask);

        if ('' === $host) {
            $this->reply->reply($sender, 'The mask needs a host part, for example user@host or *@host.');

            return;
        }

        $reason = [] === $args ? 'G-Lined by OperServ' : implode(' ', array_values($args));

        $this->enforcement->gline($user, $host, $duration, $reason);
        $this->reply->reply($sender, sprintf(
            'G-Line added on %s@%s (%s).',
            $user,
            $host,
            Duration::describe($duration),
        ));
    }

    /** @param list<string> $args */
    private function onUngline(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireOper($sender, $state)) {
            return;
        }

        $mask = array_shift($args);

        if (null === $mask) {
            $this->reply->reply($sender, 'Syntax: UNGLINE <user@host>');

            return;
        }

        [$user, $host] = $this->splitMask($mask);

        $this->enforcement->ungline($user, $host);
        $this->reply->reply($sender, sprintf('G-Line removed on %s@%s.', $user, $host));
    }

    /** @return array{string, string} user and host parts of a mask */
    private function splitMask(string $mask): array
    {
        $position = strpos($mask, '@');

        if (false === $position) {
            return ['*', $mask];
        }

        return [substr($mask, 0, $position), substr($mask, $position + 1)];
    }

    /** @param list<string> $args */
    private function onRename(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireOper($sender, $state)) {
            return;
        }

        $nick = array_shift($args);

        if (null === $nick) {
            $this->reply->reply($sender, 'Syntax: RENAME <nick> [newnick]');

            return;
        }

        $uid = $state->uidOf($nick);

        if (null === $uid) {
            $this->reply->reply($sender, sprintf('%s is not online.', $nick));

            return;
        }

        $newNick = $args[0] ?? ('Guest' . random_int(1000, 9999));

        $this->enforcement->rename($uid, $newNick);
        $this->reply->reply($sender, sprintf('%s has been renamed to %s.', $nick, $newNick));
    }

    /** @param list<string> $args */
    private function onDropNdb(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireOper($sender, $state)) {
            return;
        }

        $nick = array_shift($args);

        if (null === $nick) {
            $this->reply->reply($sender, 'Syntax: DROPNDB <nick>');

            return;
        }

        try {
            $account = new Nick($nick);
        } catch (InvalidArgumentException) {
            $this->reply->reply($sender, 'That nickname cannot be in the database.');

            return;
        }

        if (null === $this->accounts->findByNick($account)) {
            $this->reply->reply($sender, sprintf('%s is not registered.', $nick));

            return;
        }

        $this->accounts->delete($account);
        $this->accountProjection->remove($account->value);

        $this->reply->reply($sender, sprintf('%s has been removed from the database.', $account->value));
    }

    /** @param list<string> $args */
    private function onApodera(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireOper($sender, $state)) {
            return;
        }

        $channelName = array_shift($args);
        $newFounder = array_shift($args);

        if (null === $channelName) {
            $this->reply->reply($sender, 'Syntax: APODERA <#channel> [nick]');

            return;
        }

        try {
            $registered = $this->channels->find(new ChannelName($channelName));
        } catch (InvalidArgumentException) {
            $this->reply->reply($sender, 'That channel name cannot be registered.');

            return;
        }

        if (null === $registered) {
            $this->reply->reply($sender, sprintf('%s is not registered.', $channelName));

            return;
        }

        $founder = $newFounder ?? $state->nickOf($sender);

        if (null === $founder) {
            $this->reply->reply($sender, 'Say which nick takes the channel.');

            return;
        }

        $this->channels->updateFounder(new Channel($registered->name, $founder, $registered->createdAt, $registered->topic));
        $this->channelProjection->publishRegistered($registered->name->value);

        $this->reply->reply($sender, sprintf('%s now belongs to %s.', $registered->name->value, $founder));
    }

    private function onStats(string $sender, NetworkState $state): void
    {
        $this->reply->reply($sender, sprintf('Users online: %d.', $state->count()));
    }

    private function onIrcops(string $sender, NetworkState $state): void
    {
        $operators = [];

        foreach ($state->users() as $uid => $nick) {
            if ($state->hasMode($uid, 'o')) {
                $operators[] = $nick;
            }
        }

        $this->reply->reply($sender, [] === $operators
            ? 'No IRC operators are online.'
            : sprintf('IRC operators online: %s.', implode(', ', $operators)));
    }

    private function requireOper(string $sender, NetworkState $state): bool
    {
        if ($state->hasMode($sender, 'o')) {
            return true;
        }

        $this->reply->reply($sender, 'This command is restricted to IRC operators.');

        return false;
    }

    private function onHelp(string $uid): void
    {
        $this->reply->reply($uid, 'Commands: KILL, KICK, OP, DEOP, MODE, GLINE, UNGLINE, RENAME <nick> [newnick], DROPNDB <nick>, APODERA <#channel> [nick], STATS, IRCOPS.');
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
