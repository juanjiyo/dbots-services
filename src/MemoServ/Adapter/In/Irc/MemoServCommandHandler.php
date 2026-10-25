<?php

declare(strict_types=1);

namespace Dbots\MemoServ\Adapter\In\Irc;

use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\AccountResolver;
use Dbots\Irc\Application\Help\HelpCatalog;
use Dbots\Irc\Application\Help\IrcFormat;
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
use function ctype_digit;
use function explode;
use function implode;
use function ltrim;
use function sprintf;
use function str_contains;
use function strcasecmp;
use function strpos;
use function strtoupper;
use function substr;
use function trim;

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
        private HelpCatalog $help,
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
        $parts = $this->tokenise($rest);
        $account = $this->accounts->resolve($sender, $state);

        match ($command) {
            'SEND', 'ENVIA' => $this->onSend($sender, $account, $rest),
            'LIST', 'LISTA' => $this->onList($sender, $account),
            'READ', 'LEE' => $this->onRead($sender, $account, $rest),
            'DELETE', 'DEL', 'BORRA' => $this->onDelete($sender, $account, $rest),
            'HELP', 'AYUDA' => $this->onHelp($sender, $parts, $state),
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
        if (null === $account) {
            $this->reply->reply($uid, 'Debes estar identificado para leer tus memos.');

            return;
        }

        // LAST is the newest memo, the way the original spells it.
        $id = 'LAST' === strtoupper(trim($rest)) ? $this->memos->lastIdFor($account) : $this->identifier($rest);

        if (null === $id) {
            $this->reply->reply($uid, str_contains(strtoupper($rest), 'LAST')
                ? 'No tienes memos.'
                : 'Sintaxis: READ <número>|LAST');

            return;
        }

        $memo = ($this->read)($id, $account);

        if (null === $memo) {
            $this->reply->reply($uid, sprintf('No tienes el memo %d.', $id));

            return;
        }

        $this->reply->reply($uid, sprintf(
            'Mensaje %s de %s (%s). Para borrarlo, escriba %s',
            IrcFormat::paint(IrcFormat::COMMAND, (string) $id),
            IrcFormat::paint(IrcFormat::COMMAND, $memo->sender),
            IrcFormat::paint(IrcFormat::COMMAND, $memo->sentAt->format('H:i:s d/M/Y')),
            IrcFormat::paint(IrcFormat::COMMAND, sprintf('/msg MeMo DEL %d', $id)),
        ));
        $this->reply->reply($uid, $memo->body);
    }

    /** Announces unread memos when someone identifies, as the original does. */
    public function notifyPending(string $uid, string $account): void
    {
        $unread = $this->memos->unreadFor($account);

        foreach ($unread as $memo) {
            $this->reply->reply($uid, sprintf(
                'Tienes un mensaje nuevo de %s. Mensaje número %s. Escribe %s para leerlo.',
                IrcFormat::paint(IrcFormat::COMMAND, $memo->sender),
                IrcFormat::paint(IrcFormat::COMMAND, (string) $memo->id),
                IrcFormat::paint(IrcFormat::COMMAND, '/msg MeMo READ LAST'),
            ));
        }
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

    /** @param list<string> $args */
    private function onHelp(string $uid, array $args, NetworkState $state): void
    {
        $isOper = $state->hasMode($uid, 'o');

        foreach ($this->help->render(implode(' ', $args), $isOper) as $line) {
            $this->reply->reply($uid, $line);
        }
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
