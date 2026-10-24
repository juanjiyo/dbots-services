<?php

declare(strict_types=1);

namespace Dbots\NickServ\Adapter\In\Irc;

use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\NetworkState;
use Dbots\Irc\Application\AccountResolver;
use Dbots\NickServ\Application\ChangePassword\ChangePasswordHandler;
use Dbots\NickServ\Application\ChangePassword\ChangePasswordResult;
use Dbots\NickServ\Application\DropNick\DropNickHandler;
use Dbots\NickServ\Application\DropNick\DropNickResult;
use Dbots\NickServ\Application\Identify\IdentifyHandler;
use Dbots\NickServ\Application\Identify\IdentifyResult;
use Dbots\NickServ\Application\Port\Out\AccountProjectionInterface;
use Dbots\NickServ\Application\Port\Out\ReplyInterface;
use Dbots\NickServ\Application\RegisterNick\RegisterNickHandler;
use Dbots\NickServ\Application\RegisterNick\RegisterNickResult;

use function array_filter;
use function array_shift;
use function array_values;
use function explode;
use function strcasecmp;
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
            'HELP', 'AYUDA' => $this->onHelp($sender),
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

    private function onHelp(string $uid): void
    {
        $this->reply->reply($uid, 'Commands: REGISTER <password> [email], IDENTIFY <password>, SET PASSWORD <current> <new>, DROP <password>.');
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
