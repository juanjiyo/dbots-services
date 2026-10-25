<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\SendPassword;

use DateTimeImmutable;
use Dbots\Domain\Account\Account;
use Dbots\Domain\Account\Nick;
use Dbots\Domain\Account\Password;
use Dbots\Domain\Memo\Memo;
use Dbots\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use Dbots\NickServ\Application\Port\Out\AccountRepositoryInterface;
use InvalidArgumentException;

use function bin2hex;
use function random_bytes;
use function sprintf;

final readonly class SendPasswordHandler
{
    public function __construct(
        private AccountRepositoryInterface $accounts,
        private MemoRepositoryInterface $memos,
    ) {
    }

    public function __invoke(string $targetNick): SendPasswordResult
    {
        try {
            $nick = new Nick($targetNick);
        } catch (InvalidArgumentException) {
            return SendPasswordResult::unknownAccount();
        }

        $account = $this->accounts->findByNick($nick);

        if (null === $account) {
            return SendPasswordResult::unknownAccount();
        }

        $plain = $this->generatePassword();
        $password = Password::fromPlain($plain);

        $this->accounts->update(new Account(
            $account->nick,
            $password->hash(),
            $account->email,
            $account->createdAt,
            $account->forbidReason,
            $account->suspendReason,
            $account->swhois,
            $account->vhost,
            $account->noexpire,
        ));

        $this->memos->save(new Memo(
            null,
            $account->nick->value,
            'NickServ',
            sprintf('An operator has reset your password. Your temporary password is: %s', $plain),
            new DateTimeImmutable(),
        ));

        return SendPasswordResult::sent($account->nick->value, $plain);
    }

    private function generatePassword(): string
    {
        return bin2hex(random_bytes(8));
    }
}
