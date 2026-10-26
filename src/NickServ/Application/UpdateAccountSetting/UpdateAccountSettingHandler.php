<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\UpdateAccountSetting;

use Dbots\Domain\Account\Account;
use Dbots\NickServ\Application\Port\Out\AccountRepositoryInterface;

use function in_array;
use function strtoupper;

final readonly class UpdateAccountSettingHandler
{
    public function __construct(private AccountRepositoryInterface $accounts)
    {
    }

    public function __invoke(?string $account, string $option, string $value): UpdateAccountSettingResult
    {
        if (null === $account) {
            return UpdateAccountSettingResult::notIdentified();
        }

        $stored = $this->accounts->findByNick(new \Dbots\Domain\Account\Nick($account));

        if (null === $stored) {
            return UpdateAccountSettingResult::notIdentified();
        }

        $error = $this->validate($option, $value);

        if (null !== $error) {
            return UpdateAccountSettingResult::invalidValue($error);
        }

        $this->accounts->update($this->apply($stored, $option, $value));

        return UpdateAccountSettingResult::changed();
    }

    private function validate(string $option, string $value): ?string
    {
        return match (strtoupper($option)) {
            'KILL' => '' === $value || in_array(strtoupper($value), ['ON', 'QUICK', 'OFF'], true)
                ? null
                : 'Syntax: SET KILL ON|QUICK|OFF',
            'QUIT', 'PRIVATE' => '' === $value || in_array(strtoupper($value), ['ON', 'OFF'], true)
                ? null
                : 'Syntax: SET QUIT ON|OFF',
            default => null,
        };
    }

    private function apply(Account $account, string $option, string $value): Account
    {
        return match (strtoupper($option)) {
            'EMAIL' => new Account(
                $account->nick,
                $account->passwordHash,
                '' === $value ? null : $value,
                $account->createdAt,
                $account->forbidReason,
                $account->suspendReason,
                $account->swhois,
                $account->vhost,
                $account->noexpire,
                $account->url,
                $account->quitVisible,
                $account->private,
                $account->kill,
                $account->automode,
            ),
            'URL' => new Account(
                $account->nick,
                $account->passwordHash,
                $account->email,
                $account->createdAt,
                $account->forbidReason,
                $account->suspendReason,
                $account->swhois,
                $account->vhost,
                $account->noexpire,
                '' === $value ? null : $value,
                $account->quitVisible,
                $account->private,
                $account->kill,
                $account->automode,
            ),
            'QUIT' => new Account(
                $account->nick,
                $account->passwordHash,
                $account->email,
                $account->createdAt,
                $account->forbidReason,
                $account->suspendReason,
                $account->swhois,
                $account->vhost,
                $account->noexpire,
                $account->url,
                'ON' === strtoupper($value),
                $account->private,
                $account->kill,
                $account->automode,
            ),
            'PRIVATE' => new Account(
                $account->nick,
                $account->passwordHash,
                $account->email,
                $account->createdAt,
                $account->forbidReason,
                $account->suspendReason,
                $account->swhois,
                $account->vhost,
                $account->noexpire,
                $account->url,
                $account->quitVisible,
                'ON' === strtoupper($value),
                $account->kill,
                $account->automode,
            ),
            'KILL' => new Account(
                $account->nick,
                $account->passwordHash,
                $account->email,
                $account->createdAt,
                $account->forbidReason,
                $account->suspendReason,
                $account->swhois,
                $account->vhost,
                $account->noexpire,
                $account->url,
                $account->quitVisible,
                $account->private,
                'OFF' === strtoupper($value) ? null : strtoupper($value),
                $account->automode,
            ),
            'AUTOMODE' => new Account(
                $account->nick,
                $account->passwordHash,
                $account->email,
                $account->createdAt,
                $account->forbidReason,
                $account->suspendReason,
                $account->swhois,
                $account->vhost,
                $account->noexpire,
                $account->url,
                $account->quitVisible,
                $account->private,
                $account->kill,
                '' === $value ? null : $value,
            ),
            default => $account,
        };
    }
}
