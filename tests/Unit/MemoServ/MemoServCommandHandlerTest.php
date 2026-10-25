<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\MemoServ;

use DateTimeImmutable;
use Dbots\Domain\Account\Account;
use Dbots\Domain\Account\Nick;
use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\AccountResolver;
use Dbots\Irc\Application\NetworkState;
use Dbots\MemoServ\Adapter\In\Irc\MemoServCommandHandler;
use Dbots\MemoServ\Application\DeleteMemo\DeleteMemoHandler;
use Dbots\MemoServ\Application\Help\MemoServHelp;
use Dbots\MemoServ\Application\ReadMemo\ReadMemoHandler;
use Dbots\MemoServ\Application\SendMemo\SendMemoHandler;
use Dbots\Tests\Support\InMemoryAccountRepository;
use Dbots\Tests\Support\InMemoryMemoRepository;
use Dbots\Tests\Support\RecordingIrc;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MemoServCommandHandlerTest extends TestCase
{
    private InMemoryMemoRepository $memos;
    private RecordingIrc $irc;
    private MemoServCommandHandler $handler;
    private NetworkState $state;

    protected function setUp(): void
    {
        $accounts = new InMemoryAccountRepository();
        $accounts->save(new Account(new Nick('alice'), 'hash', null, new DateTimeImmutable()));
        $accounts->save(new Account(new Nick('bob'), 'hash', null, new DateTimeImmutable()));

        $this->memos = new InMemoryMemoRepository();
        $this->irc = new RecordingIrc();
        $this->handler = new MemoServCommandHandler(
            new SendMemoHandler($this->memos, $accounts),
            new ReadMemoHandler($this->memos),
            new DeleteMemoHandler($this->memos),
            $this->memos,
            new AccountResolver($accounts),
            $this->irc,
            'MeMo',
            '003A00003',
            MemoServHelp::create(),
        );

        $this->state = new NetworkState();
        $this->state->introduceUser('001AAAAAA', 'alice', null, 'r');
        $this->state->introduceUser('001BBBBBB', 'bob', null, 'r');
    }

    #[Test]
    public function itSendsAMemoToAnotherAccount(): void
    {
        $this->handler->handle($this->privmsg('send bob hello there'), $this->state);

        self::assertSame(['001AAAAAA: Memo sent to bob.'], $this->irc->replies);
        self::assertSame(1, $this->memos->countUnread('bob'));
    }

    #[Test]
    public function itRefusesToSendToAnUnknownAccount(): void
    {
        $this->handler->handle($this->privmsg('send nobody hi'), $this->state);

        self::assertSame(['001AAAAAA: nobody is not a registered account.'], $this->irc->replies);
        self::assertSame(0, $this->memos->countUnread('nobody'));
    }

    #[Test]
    public function itRefusesToSendWhenNotIdentified(): void
    {
        $anonymous = new NetworkState();
        $anonymous->introduceUser('001CCCCCC', 'carol', null, 'iwx');

        $this->handler->handle($this->privmsg('send bob hi', '001CCCCCC'), $anonymous);

        self::assertSame(['001CCCCCC: You must be identified to send memos.'], $this->irc->replies);
    }

    #[Test]
    public function itListsOnlyTheCallersMemos(): void
    {
        $this->sendToBob();

        $this->handler->handle($this->privmsg('list', '001BBBBBB'), $this->state);

        self::assertStringContainsString('You have 1 memo(s)', $this->irc->replies[0]);
        self::assertStringContainsString('from alice', $this->irc->replies[1]);

        $this->irc->replies = [];
        $this->handler->handle($this->privmsg('list', '001AAAAAA'), $this->state);

        self::assertSame(['001AAAAAA: You have no memos.'], $this->irc->replies);
    }

    #[Test]
    public function itReadsAMemoAndMarksItRead(): void
    {
        $this->handler->handle($this->privmsg('send bob the body'), $this->state);
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('read 1', '001BBBBBB'), $this->state);

        $plain = \Dbots\Irc\Application\Help\IrcFormat::strip($this->irc->replies[0]);
        self::assertStringContainsString('Mensaje 1 de alice', $plain);
        self::assertSame('001BBBBBB: the body', $this->irc->replies[1]);
        self::assertSame(0, $this->memos->countUnread('bob'));
    }

    #[Test]
    public function itWillNotReadSomebodyElsesMemo(): void
    {
        $this->handler->handle($this->privmsg('send bob secret'), $this->state);
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('read 1', '001AAAAAA'), $this->state);

        self::assertSame(['001AAAAAA: No tienes el memo 1.'], $this->irc->replies);
        self::assertSame(1, $this->memos->countUnread('bob'));
    }

    #[Test]
    public function itDeletesOnlyItsOwnMemo(): void
    {
        $this->handler->handle($this->privmsg('send bob bye'), $this->state);
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('delete 1', '001AAAAAA'), $this->state);
        self::assertSame('001AAAAAA: You have no memo #1.', $this->irc->replies[0]);

        $this->irc->replies = [];
        $this->handler->handle($this->privmsg('delete 1', '001BBBBBB'), $this->state);
        self::assertSame(['001BBBBBB: Memo #1 deleted.'], $this->irc->replies);
        self::assertSame([], $this->memos->forRecipient('bob'));
    }

    #[Test]
    public function itRejectsANonNumericIdentifier(): void
    {
        $this->handler->handle($this->privmsg('read banana'), $this->state);

        self::assertSame(['001AAAAAA: Sintaxis: READ <número>|LAST'], $this->irc->replies);
    }

    #[Test]
    public function itIgnoresMessagesForAnotherService(): void
    {
        $this->handler->handle(new IrcMessage('001AAAAAA', 'PRIVMSG', ['CHaN'], 'list'), $this->state);

        self::assertSame([], $this->irc->replies);
    }

    private function privmsg(string $text, string $uid = '001AAAAAA'): IrcMessage
    {
        return new IrcMessage($uid, 'PRIVMSG', ['MeMo'], $text);
    }

    private function sendToBob(): void
    {
        $this->handler->handle($this->privmsg('send bob hello'), $this->state);
        $this->irc->replies = [];
    }

    #[Test]
    public function itAnswersTheHelpIndexWithoutCrashing(): void
    {
        $this->handler->handle($this->privmsg('HELP'), $this->state);

        self::assertNotEmpty($this->irc->replies);
        self::assertStringContainsString('MeMo', $this->irc->replies[0]);
    }
}
