<?php

declare(strict_types=1);

namespace Dbots\MemoServ\Adapter\In\Irc;

use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\AccountResolver;
use Dbots\Irc\Application\NetworkState;
use Dbots\MemoServ\Application\DeleteMemo\DeleteMemoHandler;
use Dbots\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use Dbots\MemoServ\Application\ReadMemo\ReadMemoHandler;
use Dbots\MemoServ\Application\SendMemo\SendMemoHandler;
use Dbots\MemoServ\Application\SendMemo\SendMemoResult;
use Dbots\NickServ\Application\Port\Out\ReplyInterface;

use function array_filter;
use function array_shift;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function implode;
use function ctype_digit;
use function ltrim;
use function sprintf;
use function strcasecmp;
use function strpos;
use function strtoupper;
use function substr;

final readonly class MemoServCommandHandler
{
    public function __construct(
        private SendMemoHandler $send,
        private ReadMemoHandler $read,
        private DeleteMemoHandler $delete,
        private MemoRepositoryInterface $memos,
        private AccountResolver $accounts,
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

        $text = $message->text();
        $space = strpos($text, ' ');
        $command = strtoupper(false === $space ? $text : substr($text, 0, $space));
        $rest = false === $space ? '' : substr($text, $space + 1);
        $account = $this->accounts->resolve($sender, $state);

        match ($command) {
            'SEND', 'ENVIA' => $this->onSend($sender, $account, $rest),
            'LIST', 'LISTA' => $this->onList($sender, $account),
            'READ', 'LEE' => $this->onRead($sender, $account, $rest),
            'DELETE', 'DEL', 'BORRA' => $this->onDelete($sender, $account, $rest),
            'HELP', 'AYUDA' => $this->onHelp($sender),
            default => $this->reply->reply($sender, 'Unknown command. Use HELP to list the available commands.'),
        };
    }

    private function onSend(string $uid, ?string $account, string $rest): void
    {
        $parts = $this->tokenise($rest);

        if (count($parts) < 2) {
            $this->reply->reply($uid, 'Syntax: SEND <nick> <message>');

            return;
        }

        $recipient = (string) array_shift($parts);
        $body = implode(' ', array_slice($parts, 0));

        match (($this->send)($recipient, $body, $account)) {
            SendMemoResult::Sent => $this->reply->reply($uid, sprintf('Memo sent to %s.', $recipient)),
            SendMemoResult::UnknownRecipient => $this->reply->reply($uid, sprintf('%s is not a registered account.', $recipient)),
            SendMemoResult::EmptyBody => $this->reply->reply($uid, 'The memo is empty.'),
            SendMemoResult::NotIdentified => $this->reply->reply($uid, 'You must be identified to send memos.'),
        };
    }

    private function onList(string $uid, ?string $account): void
    {
        if (null === $account) {
            $this->reply->reply($uid, 'You must be identified to read your memos.');

            return;
        }

        $memos = $this->memos->forRecipient($account);

        if ([] === $memos) {
            $this->reply->reply($uid, 'You have no memos.');

            return;
        }

        $this->reply->reply($uid, sprintf('You have %d memo(s):', count($memos)));

        foreach ($memos as $memo) {
            $this->reply->reply($uid, sprintf(
                '#%d from %s on %s%s',
                (int) $memo->id,
                $memo->sender,
                $memo->sentAt->format('Y-m-d H:i'),
                $memo->isUnread() ? ' [unread]' : '',
            ));
        }
    }

    private function onRead(string $uid, ?string $account, string $rest): void
    {
        $id = $this->identifier($rest);

        if (null === $id) {
            $this->reply->reply($uid, 'Syntax: READ <number>');

            return;
        }

        if (null === $account) {
            $this->reply->reply($uid, 'You must be identified to read your memos.');

            return;
        }

        $memo = ($this->read)($id, $account);

        if (null === $memo) {
            $this->reply->reply($uid, sprintf('You have no memo #%d.', $id));

            return;
        }

        $this->reply->reply($uid, sprintf('Memo #%d from %s (%s):', $id, $memo->sender, $memo->sentAt->format('Y-m-d H:i')));
        $this->reply->reply($uid, $memo->body);
    }

    private function onDelete(string $uid, ?string $account, string $rest): void
    {
        $id = $this->identifier($rest);

        if (null === $id) {
            $this->reply->reply($uid, 'Syntax: DELETE <number>');

            return;
        }

        $this->reply->reply($uid, ($this->delete)($id, $account)
            ? sprintf('Memo #%d deleted.', $id)
            : sprintf('You have no memo #%d.', $id));
    }

    private function onHelp(string $uid): void
    {
        $this->reply->reply($uid, 'Commands: SEND <nick> <message>, LIST, READ <number>, DELETE <number>.');
    }

    private function identifier(string $rest): ?int
    {
        $token = ltrim($this->tokenise($rest)[0] ?? '', '#');

        return '' === $token || !ctype_digit($token) ? null : (int) $token;
    }

    private function isAddressedToMe(string $target): bool
    {
        return 0 === strcasecmp($target, $this->serviceNick) || $target === $this->serviceUid;
    }

    /** @return list<string> */
    private function tokenise(string $text): array
    {
        return array_values(array_filter(
            explode(' ', $text),
            static fn (string $token): bool => '' !== $token,
        ));
    }
}
