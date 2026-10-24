<?php

declare(strict_types=1);

namespace Dbots\NickServ\Adapter\In\Irc;

use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\Help\HelpCatalog;
use Dbots\Irc\Application\NetworkState;
use Dbots\Irc\Application\AccountResolver;
use Dbots\NickServ\Application\AccountInfo\AccountInfoHandler;
use Dbots\NickServ\Application\ChangePassword\ChangePasswordHandler;
use Dbots\NickServ\Application\ChangePassword\ChangePasswordResult;
use Dbots\NickServ\Application\DropNick\DropNickHandler;
use Dbots\NickServ\Application\DropNick\DropNickResult;
use Dbots\NickServ\Application\Identify\IdentifyHandler;
use Dbots\NickServ\Application\Identify\IdentifyResult;
use Dbots\Domain\Account\Account;
use Dbots\Domain\Account\Nick;
use Dbots\NickServ\Application\Port\Out\AccountProjectionInterface;
use Dbots\NickServ\Application\Port\Out\AccountRepositoryInterface;
use Dbots\NickServ\Application\Port\Out\ReplyInterface;
use Dbots\NickServ\Application\RegisterNick\RegisterNickHandler;
use Dbots\NickServ\Application\RegisterNick\RegisterNickResult;

use function array_filter;
use function array_shift;
use function array_values;
use function explode;
use function strcasecmp;
use function implode;
use function sprintf;
use function strtoupper;

final readonly class NickServCommandHandler
{
    public function __construct(
        private RegisterNickHandler $register,
        private IdentifyHandler $identify,
        private ReplyInterface $reply,
        private AccountProjectionInterface $projection,
        private ChangePasswordHandler $changePassword,
        private DropNickHandler $dropNick,
        private AccountResolver $identity,
        private AccountInfoHandler $info,
        private AccountRepositoryInterface $accounts,
        private string $serviceNick,
        private string $serviceUid,
        private HelpCatalog $help,
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

        $nick = $state->nickOf($sender);

        if (null === $nick) {
            return;
        }

        $parts = $this->tokenise($message->text());

        if ([] === $parts) {
            return;
        }

        $command = strtoupper((string) array_shift($parts));

        match ($command) {
            'REGISTER', 'REGISTRA' => $this->onRegister($sender, $nick, $parts),
            'IDENTIFY', 'IDENTIFICA' => $this->onIdentify($sender, $nick, $parts),
            'SET' => $this->onSet($sender, $parts, $state),
            'DROP' => $this->onDrop($sender, $parts, $state),
            'INFO' => $this->onInfo($sender, $parts),
            'ESTADO', 'STATUS' => $this->onStatus($sender, $nick, $parts),
            'SUSPEND' => $this->onSuspend($sender, $parts, $state, true),
            'UNSUSPEND' => $this->onSuspend($sender, $parts, $state, false),
            'LISTSUSPEND' => $this->onListState($sender, $state, 'suspend'),
            'FORBID' => $this->onForbid($sender, $parts, $state, true),
            'UNFORBID' => $this->onForbid($sender, $parts, $state, false),
            'LISTFORBID' => $this->onListState($sender, $state, 'forbid'),
            'SWHOIS' => $this->onSwhois($sender, $parts, $state),
            'NOEXPIRE' => $this->onNoexpire($sender, $parts, $state),
            'HELP', 'AYUDA' => $this->onHelp($sender, $parts, $state),
            default => $this->reply->reply($sender, 'Unknown command. Use HELP to list the available commands.'),
        };
    }

    /** @param list<string> $args */
    private function onRegister(string $uid, string $nick, array $args): void
    {
        $password = $args[0] ?? null;

        if (null === $password) {
            $this->reply->reply($uid, 'Syntax: REGISTER <password> [email]');

            return;
        }

        $result = ($this->register)($nick, $password, $args[1] ?? null);

        match ($result) {
            RegisterNickResult::Registered => $this->onRegistered($uid, $nick),
            RegisterNickResult::AlreadyRegistered => $this->reply->reply($uid, 'That nickname is already registered.'),
            RegisterNickResult::InvalidNick => $this->reply->reply($uid, 'That nickname cannot be registered.'),
            RegisterNickResult::WeakPassword => $this->reply->reply($uid, 'The password is too short.'),
        };
    }

    private function onRegistered(string $uid, string $nick): void
    {
        // The IRCd owns identity now: publishing the profile makes the nick
        // protected, and UDB only materialises identity through its own
        // authentication, so telling the user where to identify is the whole
        // contract. SVSLOGIN would not authenticate them.
        $this->projection->publish($nick);

        $this->reply->reply($uid, sprintf(
            'Nickname registered. Identify with: /NICK %s:<password>',
            $nick,
        ));
    }

    /** @param list<string> $args */
    private function onIdentify(string $uid, string $nick, array $args): void
    {
        $password = $args[0] ?? null;

        if (null === $password) {
            $this->reply->reply($uid, 'Syntax: IDENTIFY <password>');

            return;
        }

        $result = ($this->identify)($nick, $password);

        match ($result) {
            IdentifyResult::Identified => $this->onIdentified($uid, $nick),
            IdentifyResult::UnknownAccount => $this->reply->reply($uid, 'That nickname is not registered.'),
            IdentifyResult::WrongPassword => $this->reply->reply($uid, 'Invalid password.'),
            IdentifyResult::InvalidNick => $this->reply->reply($uid, 'That nickname cannot be registered.'),
        };
    }

    private function onIdentified(string $uid, string $nick): void
    {
        $this->reply->reply($uid, sprintf(
            'Password correct. Identify with: /NICK %s:<password>',
            $nick,
        ));
    }

    /** @param list<string> $args */
    private function onSet(string $uid, array $args, NetworkState $state): void
    {
        $option = strtoupper((string) ($args[0] ?? ''));

        if ('PASSWORD' !== $option) {
            $this->reply->reply($uid, 'Syntax: SET PASSWORD <current> <new>');

            return;
        }

        $current = (string) ($args[1] ?? '');
        $new = (string) ($args[2] ?? '');

        if ('' === $current || '' === $new) {
            $this->reply->reply($uid, 'Syntax: SET PASSWORD <current> <new>');

            return;
        }

        match ($this->changePassword->__invoke($this->identity->resolve($uid, $state), $current, $new)) {
            ChangePasswordResult::Changed => $this->onPasswordChanged($uid, $state),
            ChangePasswordResult::WrongPassword => $this->reply->reply($uid, 'The current password is not correct.'),
            ChangePasswordResult::WeakPassword => $this->reply->reply($uid, 'The new password is too short.'),
            ChangePasswordResult::NotRegistered => $this->reply->reply($uid, 'That nickname is not registered.'),
            ChangePasswordResult::NotIdentified => $this->reply->reply($uid, 'You must be identified to change your password.'),
        };
    }

    private function onPasswordChanged(string $uid, NetworkState $state): void
    {
        $account = $this->identity->resolve($uid, $state);

        if (null !== $account) {
            $this->projection->publish($account);
        }

        $this->reply->reply($uid, 'Your password has been changed.');
    }

    /** @param list<string> $args */
    private function onDrop(string $uid, array $args, NetworkState $state): void
    {
        $password = (string) ($args[0] ?? '');

        if ('' === $password) {
            $this->reply->reply($uid, 'Syntax: DROP <password>');

            return;
        }

        $account = $this->identity->resolve($uid, $state);
        $result = $this->dropNick->__invoke($account, $password);

        if (DropNickResult::Dropped === $result && null !== $account) {
            $this->projection->remove($account);
        }

        match ($result) {
            DropNickResult::Dropped => $this->reply->reply($uid, 'Your account has been dropped.'),
            DropNickResult::WrongPassword => $this->reply->reply($uid, 'Invalid password.'),
            DropNickResult::NotRegistered => $this->reply->reply($uid, 'That nickname is not registered.'),
            DropNickResult::NotIdentified => $this->reply->reply($uid, 'You must be identified to drop your account.'),
            DropNickResult::OwnsChannels => $this->reply->reply($uid, 'Drop your registered channels first.'),
        };
    }

    /** @param list<string> $args */
    private function onInfo(string $uid, array $args): void
    {
        $nick = (string) ($args[0] ?? '');

        if ('' === $nick) {
            $this->reply->reply($uid, 'Syntax: INFO <nick>');

            return;
        }

        $lines = ($this->info)($nick);

        if (null === $lines) {
            $this->reply->reply($uid, sprintf('%s is not registered.', $nick));

            return;
        }

        foreach ($lines as $line) {
            $this->reply->reply($uid, $line);
        }
    }

    /** @param list<string> $args */
    private function onStatus(string $uid, string $nick, array $args): void
    {
        $target = (string) ($args[0] ?? $nick);

        try {
            $account = $this->accounts->findByNick(new Nick($target));
        } catch (InvalidArgumentException) {
            $this->reply->reply($uid, 'That nickname is not registered.');

            return;
        }

        if (null === $account) {
            $this->reply->reply($uid, sprintf('%s is not registered.', $target));

            return;
        }

        $this->reply->reply($uid, sprintf(
            '%s: registered %s; %s; %s; noexpire %s.',
            $account->nick->value,
            $account->createdAt->format('Y-m-d'),
            $account->isForbidden() ? 'forbidden' : 'not forbidden',
            $account->isSuspended() ? 'suspended' : 'not suspended',
            $account->noexpire ? 'on' : 'off',
        ));
    }

    /** @param list<string> $args */
    private function onSuspend(string $uid, array $args, NetworkState $state, bool $suspend): void
    {
        if (!$this->requireOperer($uid, $state)) {
            return;
        }

        $target = array_shift($args);

        if (null === $target) {
            $this->reply->reply($uid, $suspend ? 'Syntax: SUSPEND <nick> [reason]' : 'Syntax: UNSUSPEND <nick>');

            return;
        }

        $account = $this->findAccount($uid, $target);

        if (null === $account) {
            return;
        }

        $reason = [] === $args ? 'Suspended by staff' : implode(' ', array_values($args));

        $this->accounts->update(new Account(
            $account->nick,
            $account->passwordHash,
            $account->email,
            $account->createdAt,
            $account->forbidReason,
            $suspend ? $reason : null,
            $account->swhois,
            $account->vhost,
            $account->noexpire,
        ));

        $this->projection->publish($account->nick->value);
        $this->reply->reply($uid, sprintf('%s is %s.', $account->nick->value, $suspend ? 'now suspended' : 'no longer suspended'));
    }

    /** @param list<string> $args */
    private function onForbid(string $uid, array $args, NetworkState $state, bool $forbid): void
    {
        if (!$this->requireOperer($uid, $state)) {
            return;
        }

        $target = array_shift($args);

        if (null === $target) {
            $this->reply->reply($uid, $forbid ? 'Syntax: FORBID <nick> [reason]' : 'Syntax: UNFORBID <nick>');

            return;
        }

        $account = $this->findAccount($uid, $target);

        if (null === $account) {
            return;
        }

        $reason = [] === $args ? 'Forbidden by staff' : implode(' ', array_values($args));

        $this->accounts->update(new Account(
            $account->nick,
            $account->passwordHash,
            $account->email,
            $account->createdAt,
            $forbid ? $reason : null,
            $account->suspendReason,
            $account->swhois,
            $account->vhost,
            $account->noexpire,
        ));

        $this->projection->publish($account->nick->value);
        $this->reply->reply($uid, sprintf('%s is %s.', $account->nick->value, $forbid ? 'now forbidden' : 'no longer forbidden'));
    }

    private function onListState(string $uid, NetworkState $state, string $kind): void
    {
        if (!$this->requireOperer($uid, $state)) {
            return;
        }

        $matches = [];

        foreach ($this->accounts->all() as $account) {
            if ('forbid' === $kind && $account->isForbidden()) {
                $matches[] = $account->nick->value;
            }

            if ('suspend' === $kind && $account->isSuspended()) {
                $matches[] = $account->nick->value;
            }
        }

        $this->reply->reply($uid, [] === $matches
            ? sprintf('No accounts are %s.', 'forbid' === $kind ? 'forbidden' : 'suspended')
            : sprintf('%s: %s.', 'forbid' === $kind ? 'Forbidden' : 'Suspended', implode(', ', $matches)));
    }

    /** @param list<string> $args */
    private function onSwhois(string $uid, array $args, NetworkState $state): void
    {
        if (!$this->requireOperer($uid, $state)) {
            return;
        }

        $target = array_shift($args);

        if (null === $target) {
            $this->reply->reply($uid, 'Syntax: SWHOIS <nick> <text>');

            return;
        }

        $account = $this->findAccount($uid, $target);

        if (null === $account) {
            return;
        }

        $text = implode(' ', array_values($args));

        $this->accounts->update(new Account(
            $account->nick,
            $account->passwordHash,
            $account->email,
            $account->createdAt,
            $account->forbidReason,
            $account->suspendReason,
            '' === $text ? null : $text,
            $account->vhost,
            $account->noexpire,
        ));

        $this->projection->publish($account->nick->value);
        $this->reply->reply($uid, '' === $text
            ? sprintf('SWHOIS cleared for %s.', $account->nick->value)
            : sprintf('SWHOIS set for %s.', $account->nick->value));
    }

    /** @param list<string> $args */
    private function onNoexpire(string $uid, array $args, NetworkState $state): void
    {
        if (!$this->requireOperer($uid, $state)) {
            return;
        }

        $target = array_shift($args);

        if (null === $target) {
            $this->reply->reply($uid, 'Syntax: NOEXPIRE <nick>');

            return;
        }

        $account = $this->findAccount($uid, $target);

        if (null === $account) {
            return;
        }

        $this->accounts->update(new Account(
            $account->nick,
            $account->passwordHash,
            $account->email,
            $account->createdAt,
            $account->forbidReason,
            $account->suspendReason,
            $account->swhois,
            $account->vhost,
            !$account->noexpire,
        ));

        $this->projection->publish($account->nick->value);
        $this->reply->reply($uid, sprintf('NOEXPIRE is now %s for %s.', $account->noexpire ? 'off' : 'on', $account->nick->value));
    }

    private function findAccount(string $uid, string $nick): ?Account
    {
        try {
            $account = $this->accounts->findByNick(new Nick($nick));
        } catch (InvalidArgumentException) {
            $this->reply->reply($uid, 'That nickname is not registered.');

            return null;
        }

        if (null === $account) {
            $this->reply->reply($uid, sprintf('%s is not registered.', $nick));
        }

        return $account;
    }

    private function requireOperer(string $uid, NetworkState $state): bool
    {
        if ($state->hasMode($uid, 'o')) {
            return true;
        }

        $this->reply->reply($uid, 'This command is restricted to IRC operators.');

        return false;
    }

    /** @param list<string> $args */
    private function onHelp(string $uid, array $args, NetworkState $state): void
    {
        $isOper = $state->hasMode($uid, 'o');

        foreach ($this->help->render(implode(' ', $args), $isOper) as $line) {
            $this->reply->reply($uid, $line);
        }
    }

    private function isAddressedToMe(string $target): bool
    {
        return 0 === strcasecmp($target, $this->serviceNick) || $target === $this->serviceUid;
    }

    /**
     * @return list<string>
     */
    private function tokenise(string $text): array
    {
        $tokens = array_values(array_filter(
            explode(' ', $text),
            static fn (string $token): bool => '' !== $token,
        ));

        return $tokens;
    }
}
