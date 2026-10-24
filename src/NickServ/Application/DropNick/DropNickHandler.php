<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\DropNick;

use Dbots\ChanServ\Application\Port\Out\ChannelRepositoryInterface;
use Dbots\Domain\Account\Nick;
use Dbots\Domain\Account\Password;
use Dbots\NickServ\Application\Port\Out\AccountRepositoryInterface;
use InvalidArgumentException;

final readonly class DropNickHandler
{
    public function __construct(
        private AccountRepositoryInterface $accounts,
        private ChannelRepositoryInterface $channels,
    ) {
    }

    public function __invoke(?string $account, string $password): DropNickResult
    {
        if (null === $account) {
            return DropNickResult::NotIdentified;
        }

        try {
            $nick = new Nick($account);
        } catch (InvalidArgumentException) {
            return DropNickResult::NotRegistered;
        }

        $stored = $this->accounts->findByNick($nick);

        if (null === $stored) {
            return DropNickResult::NotRegistered;
        }

        try {
            $secret = Password::fromPlain($password);
        } catch (InvalidArgumentException) {
            return DropNickResult::WrongPassword;
        }

        if (!$stored->authenticatesWith($secret)) {
            return DropNickResult::WrongPassword;
        }

        foreach ($this->channels->all() as $channel) {
            if ($channel->isFoundedBy($stored->nick->value)) {
                return DropNickResult::OwnsChannels;
            }
        }

        $this->accounts->delete($nick);

        return DropNickResult::Dropped;
    }
}
