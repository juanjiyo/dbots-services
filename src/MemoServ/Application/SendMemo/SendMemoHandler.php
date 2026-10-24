<?php

declare(strict_types=1);

namespace Dbots\MemoServ\Application\SendMemo;

use DateTimeImmutable;
use Dbots\Domain\Account\Nick;
use Dbots\Domain\Memo\Memo;
use Dbots\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use Dbots\NickServ\Application\Port\Out\AccountRepositoryInterface;
use InvalidArgumentException;

use function trim;

final readonly class SendMemoHandler
{
    public function __construct(
        private MemoRepositoryInterface $memos,
        private AccountRepositoryInterface $accounts,
    ) {
    }

    public function __invoke(string $recipient, string $body, ?string $sender): SendMemoResult
    {
        if (null === $sender) {
            return SendMemoResult::NotIdentified;
        }

        $body = trim($body);

        if ('' === $body) {
            return SendMemoResult::EmptyBody;
        }

        try {
            $recipientAccount = $this->accounts->findByNick(new Nick($recipient));
        } catch (InvalidArgumentException) {
            return SendMemoResult::UnknownRecipient;
        }

        if (null === $recipientAccount) {
            return SendMemoResult::UnknownRecipient;
        }

        $this->memos->save(new Memo(
            null,
            $recipientAccount->nick->value,
            $sender,
            $body,
            new DateTimeImmutable(),
        ));

        return SendMemoResult::Sent;
    }
}
