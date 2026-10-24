<?php

declare(strict_types=1);

namespace Dbots\NickServ\Adapter\In\Irc;

use Dbots\Domain\Account\Nick;
use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\NetworkState;
use Dbots\NickServ\Application\Identify\IdentifyHandler;
use Dbots\NickServ\Application\Identify\IdentifyResult;
use Dbots\NickServ\Application\Port\Out\AccountLoginInterface;
use Dbots\NickServ\Application\Port\Out\ReplyInterface;
use Dbots\NickServ\Application\RegisterNick\RegisterNickHandler;
use Dbots\NickServ\Application\RegisterNick\RegisterNickResult;
use InvalidArgumentException;

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
        private AccountLoginInterface $login,
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
        try {
            $this->login->login($uid, new Nick($nick));
        } catch (InvalidArgumentException) {
            // Registration already succeeded; a login failure must not hide it.
        }

        $this->reply->reply($uid, 'Nickname registered. You are now identified.');
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
        try {
            $this->login->login($uid, new Nick($nick));
        } catch (InvalidArgumentException) {
            $this->reply->reply($uid, 'Unable to complete the login.');

            return;
        }

        $this->reply->reply($uid, 'Password accepted. You are now identified.');
    }

    private function onHelp(string $uid): void
    {
        $this->reply->reply($uid, 'Commands: REGISTER <password> [email], IDENTIFY <password>.');
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
