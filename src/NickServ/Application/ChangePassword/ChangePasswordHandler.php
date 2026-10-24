<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\ChangePassword;

use Dbots\Domain\Account\Account;
use Dbots\Domain\Account\Nick;
use Dbots\Domain\Account\Password;
use Dbots\NickServ\Application\Port\Out\AccountRepositoryInterface;
use InvalidArgumentException;

final readonly class ChangePasswordHandler
{
    public function __construct(private AccountRepositoryInterface $accounts) {}

    public function __invoke(?string $account, string $current, string $new): ChangePasswordResult
    {
        if (null === $account) {
            return ChangePasswordResult::NotIdentified;
        }

        try {
            $nick = new Nick($account);
        } catch (InvalidArgumentException) {
            return ChangePasswordResult::NotRegistered;
        }

        $stored = $this->accounts->findByNick($nick);

        if (null === $stored) {
            return ChangePasswordResult::NotRegistered;
        }

        $currentPassword = $this->password($current);

        if (null === $currentPassword || !$stored->authenticatesWith($currentPassword)) {
            return ChangePasswordResult::WrongPassword;
        }

        $newPassword = $this->password($new);

        if (null === $newPassword) {
            return ChangePasswordResult::WeakPassword;
        }

        $this->accounts->update(new Account($stored->nick, $newPassword->hash(), $stored->email, $stored->createdAt));

        return ChangePasswordResult::Changed;
    }

    private function password(string $plain): ?Password
    {
        try {
            return Password::fromPlain($plain);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
