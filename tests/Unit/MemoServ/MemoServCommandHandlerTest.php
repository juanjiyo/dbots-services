<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\MemoServ;

use DateTimeImmutable;
use Dbots\Domain\Account\Account;
use Dbots\Domain\Account\Nick;
use Dbots\Domain\Memo\MemoNotifyMode;
use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\AccountResolver;
use Dbots\Irc\Application\NetworkState;
use Dbots\MemoServ\Adapter\In\Irc\MemoServCommandHandler;
use Dbots\MemoServ\Application\DeleteMemo\DeleteMemoHandler;
use Dbots\MemoServ\Application\Help\MemoServHelp;
use Dbots\MemoServ\Application\ReadMemo\ReadMemoHandler;
use Dbots\MemoServ\Application\SendMemo\SendMemoHandler;
use Dbots\Tests\Support\InMemoryAccountRepository;
use Dbots\Tests\Support\InMemoryMemoPreferencesRepository;
use Dbots\Tests\Support\InMemoryMemoRepository;
use Dbots\Tests\Support\RecordingIrc;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MemoServCommandHandlerTest extends TestCase
{
    private InMemoryMemoRepository $memos;
    private InMemoryMemoPreferencesRepository $preferences;
    private InMemoryAccountRepository $accounts;
    private RecordingIrc $irc;
    private MemoServCommandHandler $handler;
    private NetworkState $state;

    protected function setUp(): void
    {
        $this->accounts = new InMemoryAccountRepository();
        $this->accounts->save(new Account(new Nick('alice'), 'hash', null, new DateTimeImmutable()));
        $this->accounts->save(new Account(new Nick('bob'), 'hash', null, new DateTimeImmutable()));
        $this->accounts->save(new Account(new Nick('carol'), 'hash', null, new DateTimeImmutable()));

        $this->memos = new InMemoryMemoRepository();
        $this->preferences = new InMemoryMemoPreferencesRepository();
        $this->irc = new RecordingIrc();
        $this->handler = new MemoServCommandHandler(
            new SendMemoHandler($this->memos, $this->accounts, $this->preferences),
            new ReadMemoHandler($this->memos),
            new DeleteMemoHandler($this->memos),
            $this->memos,
            $this->preferences,
            $this->accounts,
            new AccountResolver($this->accounts),
            $this->irc,
            'MeMo',
            '003A00003',
            MemoServHelp::create(),
        );

        $this->state = new NetworkState();
        $this->state->introduceUser('001AAAAAA', 'alice', 'alice', 'r');
        $this->state->introduceUser('001BBBBBB', 'bob', 'bob', 'r');
        $this->state->introduceUser('001CCCCCC', 'carol', null, 'iwx');
    }

    #[Test]
    public function itSendsAMemoToAnotherAccount(): void
    {
        $this->handler->handle($this->privmsg('send bob hello there'), $this->state);

        self::assertSame(['001AAAAAA: Memo sent to bob.'], array_slice($this->irc->replies, 0, 1));
        self::assertSame(1, $this->memos->countUnread('bob'));
    }

    #[Test]
    public function itNotifiesAnOnlineRecipientImmediatelyInOnMode(): void
    {
        $this->handler->handle($this->privmsg('send bob hello there'), $this->state);

        self::assertCount(2, $this->irc->replies);
        self::assertStringContainsString('Memo sent to bob.', $this->irc->replies[0]);

        $plain = \Dbots\Irc\Application\Help\IrcFormat::strip($this->irc->replies[1]);
        self::assertStringContainsString('Tienes un mensaje nuevo de alice', $plain);
        self::assertStringContainsString('Mensaje número 1', $plain);
    }

    #[Test]
    public function itNotifiesAnOnlineRecipientImmediatelyInNewMode(): void
    {
        $this->preferences->setNotifyMode('bob', MemoNotifyMode::New);
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('send bob hello there'), $this->state);

        self::assertCount(2, $this->irc->replies);
    }

    #[Test]
    public function itDoesNotNotifyWhenTheRecipientSetsLogonMode(): void
    {
        $this->preferences->setNotifyMode('bob', MemoNotifyMode::Logon);
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('send bob hello there'), $this->state);

        self::assertCount(1, $this->irc->replies);
    }

    #[Test]
    public function itDoesNotNotifyWhenTheRecipientSetsOffMode(): void
    {
        $this->preferences->setNotifyMode('bob', MemoNotifyMode::Off);
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('send bob hello there'), $this->state);

        self::assertCount(1, $this->irc->replies);
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
        $this->handler->handle($this->privmsg('send bob hi', '001CCCCCC'), $this->state);

        self::assertSame(['001CCCCCC: You must be identified to send memos.'], $this->irc->replies);
    }

    #[Test]
    public function itRefusesToSendWhenBlacklisted(): void
    {
        $this->preferences->addToBlacklist('bob', 'alice');

        $this->handler->handle($this->privmsg('send bob hi'), $this->state);

        self::assertSame(['001AAAAAA: bob has you on their no-memo list.'], $this->irc->replies);
        self::assertSame(0, $this->memos->countUnread('bob'));
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

    #[Test]
    public function itAddsAndListsBlacklistEntries(): void
    {
        $this->handler->handle($this->privmsg('nomemos add alice', '001BBBBBB'), $this->state);

        self::assertSame(['001BBBBBB: alice has been added to your no-memo list.'], $this->irc->replies);

        $this->irc->replies = [];
        $this->handler->handle($this->privmsg('nomemos list', '001BBBBBB'), $this->state);

        self::assertSame(['001BBBBBB: No-memo list: alice.'], $this->irc->replies);
    }

    #[Test]
    public function itRejectsAddingAnUnknownNickToTheBlacklist(): void
    {
        $this->handler->handle($this->privmsg('nomemos add nobody', '001BBBBBB'), $this->state);

        self::assertSame(['001BBBBBB: nobody is not a registered account.'], $this->irc->replies);
    }

    #[Test]
    public function itRejectsAddingYourselfToTheBlacklist(): void
    {
        $this->handler->handle($this->privmsg('nomemos add bob', '001BBBBBB'), $this->state);

        self::assertSame(['001BBBBBB: You cannot add yourself to your own no-memo list.'], $this->irc->replies);
    }

    #[Test]
    public function itRemovesABlacklistEntry(): void
    {
        $this->preferences->addToBlacklist('bob', 'alice');

        $this->handler->handle($this->privmsg('nomemos del alice', '001BBBBBB'), $this->state);

        self::assertSame(['001BBBBBB: alice has been removed from your no-memo list.'], $this->irc->replies);
    }

    #[Test]
    public function itReportsRemovingANonExistentBlacklistEntry(): void
    {
        $this->handler->handle($this->privmsg('nomemos del alice', '001BBBBBB'), $this->state);

        self::assertSame(['001BBBBBB: alice is not on your no-memo list.'], $this->irc->replies);
    }

    #[Test]
    public function itRequiresIdentificationForNoMemos(): void
    {
        $this->handler->handle($this->privmsg('nomemos list', '001CCCCCC'), $this->state);

        self::assertSame(['001CCCCCC: You must be identified to manage your no-memo list.'], $this->irc->replies);
    }

    #[Test]
    public function itSetsTheNotifyMode(): void
    {
        $this->handler->handle($this->privmsg('set notify logon', '001BBBBBB'), $this->state);

        self::assertSame(['001BBBBBB: Notify mode set to LOGON.'], $this->irc->replies);
        self::assertSame(MemoNotifyMode::Logon, $this->preferences->getNotifyMode('bob'));
    }

    #[Test]
    public function itRejectsAnInvalidNotifyMode(): void
    {
        $this->handler->handle($this->privmsg('set notify sometimes', '001BBBBBB'), $this->state);

        self::assertSame(['001BBBBBB: Valid notify modes are: ON, LOGON, NEW, OFF.'], $this->irc->replies);
    }

    #[Test]
    public function itRequiresIdentificationForSetNotify(): void
    {
        $this->handler->handle($this->privmsg('set notify off', '001CCCCCC'), $this->state);

        self::assertSame(['001CCCCCC: You must be identified to change your memo settings.'], $this->irc->replies);
    }

    #[Test]
    public function itAnnouncesPendingMemosAtLoginWhenModeIsOn(): void
    {
        $this->handler->handle($this->privmsg('send bob hello'), $this->state);
        $this->irc->replies = [];

        $this->handler->notifyPending('001BBBBBB', 'bob');

        self::assertCount(1, $this->irc->replies);
    }

    #[Test]
    public function itAnnouncesPendingMemosAtLoginWhenModeIsLogon(): void
    {
        $this->preferences->setNotifyMode('bob', MemoNotifyMode::Logon);
        $this->handler->handle($this->privmsg('send bob hello'), $this->state);
        $this->irc->replies = [];

        $this->handler->notifyPending('001BBBBBB', 'bob');

        self::assertCount(1, $this->irc->replies);
    }

    #[Test]
    public function itSkipsLoginAnnouncementWhenModeIsNew(): void
    {
        $this->preferences->setNotifyMode('bob', MemoNotifyMode::New);
        $this->handler->handle($this->privmsg('send bob hello'), $this->state);
        $this->irc->replies = [];

        $this->handler->notifyPending('001BBBBBB', 'bob');

        self::assertSame([], $this->irc->replies);
    }

    #[Test]
    public function itSkipsLoginAnnouncementWhenModeIsOff(): void
    {
        $this->preferences->setNotifyMode('bob', MemoNotifyMode::Off);
        $this->handler->handle($this->privmsg('send bob hello'), $this->state);
        $this->irc->replies = [];

        $this->handler->notifyPending('001BBBBBB', 'bob');

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
