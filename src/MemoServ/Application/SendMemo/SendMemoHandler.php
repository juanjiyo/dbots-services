<?php

declare(strict_types=1);

namespace Dbots\MemoServ\Application\SendMemo;

use DateTimeImmutable;
use Dbots\Domain\Account\Nick;
use Dbots\Domain\Memo\Memo;
use Dbots\MemoServ\Application\Port\Out\MemoPreferencesRepositoryInterface;
use Dbots\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use Dbots\NickServ\Application\Port\Out\AccountRepositoryInterface;
use InvalidArgumentException;

use function trim;

final readonly class SendMemoHandler
{
    public function __construct(
        private MemoRepositoryInterface $memos,
        private AccountRepositoryInterface $accounts,
        private MemoPreferencesRepositoryInterface $preferences,
    ) {
    }

    public function __invoke(string $recipient, string $body, ?string $sender): SendMemoResult
    {
        if (null === $sender) {
            return SendMemoResult::notIdentified();
        }

        $body = trim($body);

        if ('' === $body) {
            return SendMemoResult::emptyBody();
        }

        try {
            $recipientAccount = $this->accounts->findByNick(new Nick($recipient));
        } catch (InvalidArgumentException) {
            return SendMemoResult::unknownRecipient();
        }

        if (null === $recipientAccount) {
            return SendMemoResult::unknownRecipient();
        }

        $recipientAccountName = $recipientAccount->nick->value;

        if ($this->preferences->isBlacklisted($recipientAccountName, $sender)) {
            return SendMemoResult::blocked();
        }

        $id = $this->memos->save(new Memo(
            null,
            $recipientAccountName,
            $sender,
            $body,
            new DateTimeImmutable(),
        ));

        return SendMemoResult::sent($id);
    }
}
