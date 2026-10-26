<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\AccountInfo;

use Dbots\ChanServ\Application\Port\Out\ChannelRepositoryInterface;
use Dbots\Domain\Account\Nick;
use Dbots\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use Dbots\NickServ\Application\Port\Out\AccountRepositoryInterface;
use InvalidArgumentException;

use function sprintf;

final readonly class AccountInfoHandler
{
    public function __construct(
        private AccountRepositoryInterface $accounts,
        private ChannelRepositoryInterface $channels,
        private MemoRepositoryInterface $memos,
    ) {
    }

    /** @return list<string>|null null when the nickname is not registered */
    public function __invoke(string $nick): ?array
    {
        try {
            $account = $this->accounts->findByNick(new Nick($nick));
        } catch (InvalidArgumentException) {
            return null;
        }

        if (null === $account) {
            return null;
        }

        $founded = [];

        foreach ($this->channels->all() as $channel) {
            if ($channel->isFoundedBy($account->nick->value)) {
                $founded[] = $channel->name->value;
            }
        }

        return [
            sprintf('Nickname %s was registered on %s.', $account->nick->value, $account->createdAt->format('Y-m-d')),
            null === $account->email
                ? 'No email address is set.'
                : sprintf('Email: %s.', $account->email),
            null === $account->url
                ? 'No URL is set.'
                : sprintf('URL: %s.', $account->url),
            sprintf('Private: %s.', $account->private ? 'yes' : 'no'),
            sprintf('Kill protection: %s.', $account->kill ?? 'off'),
            null === $account->automode
                ? 'No automode is set.'
                : sprintf('Automode: %s.', $account->automode),
            [] === $founded
                ? 'No channels are registered to this account.'
                : sprintf('Channels: %s.', implode(', ', $founded)),
            sprintf('Unread memos: %d.', $this->memos->countUnread($account->nick->value)),
        ];
    }
}
