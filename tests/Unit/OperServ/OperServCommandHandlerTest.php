<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\OperServ;

use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\NetworkState;
use Dbots\OperServ\Adapter\In\Irc\OperServCommandHandler;
use Dbots\OperServ\Application\Port\Out\EnforcementInterface;
use Dbots\Tests\Support\RecordingIrc;
use Dbots\OperServ\Application\Help\OperServHelp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OperServCommandHandlerTest extends TestCase
{
    private RecordingEnforcement $enforcement;
    private RecordingIrc $irc;
    private OperServCommandHandler $handler;
    private NetworkState $state;
    private \Dbots\Tests\Support\InMemoryAccountRepository $accounts;
    private \Dbots\Tests\Support\InMemoryChannelRepository $channels;

    protected function setUp(): void
    {
        $this->enforcement = new RecordingEnforcement();
        $this->irc = new RecordingIrc();
        $this->accounts = new \Dbots\Tests\Support\InMemoryAccountRepository();
        $this->channels = new \Dbots\Tests\Support\InMemoryChannelRepository();

        $this->handler = new OperServCommandHandler(
            $this->enforcement,
            $this->accounts,
            new \Dbots\Tests\Support\RecordingProjection(),
            $this->channels,
            new \Dbots\Tests\Support\RecordingChannelProjection(),
            new \Dbots\Tests\Support\InMemorySpamRuleRepository(),
            $this->spamProjection(),
            $this->irc,
            'OperServ',
            '003A00004',
            OperServHelp::create(),
        );
        $this->state = new NetworkState();
        $this->state->introduceUser('001AAAAAA', 'oper', null, 'o', 'staff.example');
        $this->state->introduceUser('001BBBBBB', 'victim', null, 'i', 'clone.example');
        $this->state->introduceUser('001CCCCCC', 'clone2', null, 'i', 'clone.example');
    }

    #[Test]
    public function anOperCanKill(): void
    {
        $this->handler->handle($this->privmsg('KILL victim being rude'), $this->state);

        self::assertSame(['001BBBBBB|being rude'], $this->enforcement->kills);
        self::assertSame(['001AAAAAA: victim has been killed.'], $this->irc->replies);
    }

    #[Test]
    public function aKillWithoutReasonUsesADefault(): void
    {
        $this->handler->handle($this->privmsg('kill victim'), $this->state);

        self::assertSame(['001BBBBBB|Killed by OperServ'], $this->enforcement->kills);
    }

    #[Test]
    public function aNonOperCannotKill(): void
    {
        $this->handler->handle($this->privmsg('KILL oper mine now', '001BBBBBB'), $this->state);

        self::assertSame([], $this->enforcement->kills);
        self::assertSame(['001BBBBBB: This command is restricted to IRC operators.'], $this->irc->replies);
    }

    #[Test]
    public function anOperCanKick(): void
    {
        $this->handler->handle($this->privmsg('KICK #chan victim bye now'), $this->state);

        self::assertSame(['#chan|001BBBBBB|bye now'], $this->enforcement->kicks);
    }

    #[Test]
    public function itRefusesToKillAnUnknownUser(): void
    {
        $this->handler->handle($this->privmsg('KILL ghost'), $this->state);

        self::assertSame([], $this->enforcement->kills);
        self::assertSame(['001AAAAAA: ghost is not online.'], $this->irc->replies);
    }

    #[Test]
    public function itReportsWhoIsOnline(): void
    {
        $this->handler->handle($this->privmsg('STATS'), $this->state);

        self::assertSame(['001AAAAAA: Users online: 3.'], $this->irc->replies);
    }

    #[Test]
    public function itListsIrcOperators(): void
    {
        $this->handler->handle($this->privmsg('IRCOPS'), $this->state);

        self::assertSame(['001AAAAAA: IRC operators online: oper.'], $this->irc->replies);
    }

    #[Test]
    public function anOperCanOpAndDeop(): void
    {
        $this->handler->handle($this->privmsg('OP #chan victim'), $this->state);
        $this->handler->handle($this->privmsg('DEOP #chan victim'), $this->state);

        self::assertSame(['#chan|+o|victim', '#chan|-o|victim'], $this->enforcement->modes);
    }

    #[Test]
    public function anOperCanApplyAChannelMode(): void
    {
        $this->handler->handle($this->privmsg('MODE #chan +m'), $this->state);

        self::assertSame(['#chan|+m|'], $this->enforcement->modes);
    }

    #[Test]
    public function aNonOperCannotOp(): void
    {
        $this->handler->handle($this->privmsg('OP #chan victim', '001BBBBBB'), $this->state);

        self::assertSame([], $this->enforcement->modes);
    }

    #[Test]
    public function anOperCanAddAGlineWithAUnitSuffix(): void
    {
        $this->handler->handle($this->privmsg('GLINE *@bad.example 2h abusing'), $this->state);

        self::assertSame(['*@bad.example|7200|abusing'], $this->enforcement->glines);
        self::assertStringContainsString('2h', end($this->irc->replies));
    }

    #[Test]
    public function aBareDurationMeansMinutes(): void
    {
        $this->handler->handle($this->privmsg('GLINE bad@host 30'), $this->state);

        self::assertSame(['bad@host|1800|G-Lined by OperServ'], $this->enforcement->glines);
    }

    #[Test]
    public function zeroDurationIsPermanent(): void
    {
        $this->handler->handle($this->privmsg('GLINE *@host 0 forever'), $this->state);

        self::assertSame(['*@host|0|forever'], $this->enforcement->glines);
    }

    #[Test]
    public function itRejectsAnInvalidDuration(): void
    {
        $this->handler->handle($this->privmsg('GLINE *@host bananas'), $this->state);

        self::assertSame([], $this->enforcement->glines);
        self::assertStringContainsString('Invalid duration', end($this->irc->replies));
    }

    #[Test]
    public function itRequiresAHostPart(): void
    {
        $this->handler->handle($this->privmsg('GLINE justanick 30'), $this->state);

        self::assertSame(['*@justanick|1800|G-Lined by OperServ'], $this->enforcement->glines);
    }

    #[Test]
    public function anOperCanRemoveAGline(): void
    {
        $this->handler->handle($this->privmsg('UNGLINE *@bad.example'), $this->state);

        self::assertSame(['*@bad.example'], $this->enforcement->unglines);
    }

    #[Test]
    public function aNonOperCannotGline(): void
    {
        $this->handler->handle($this->privmsg('GLINE *@host 30', '001BBBBBB'), $this->state);

        self::assertSame([], $this->enforcement->glines);
    }

    #[Test]
    public function anOperCanForceARename(): void
    {
        $this->handler->handle($this->privmsg('RENAME victim Renamed'), $this->state);

        self::assertSame(['001BBBBBB->Renamed'], $this->enforcement->renames);
    }

    #[Test]
    public function anOperCanDropANickFromTheDatabase(): void
    {
        $this->accounts->save(new \Dbots\Domain\Account\Account(new \Dbots\Domain\Account\Nick('victim'), 'hash', null, new \DateTimeImmutable()));

        $this->handler->handle($this->privmsg('DROPNDB victim'), $this->state);

        self::assertNull($this->accounts->findByNick(new \Dbots\Domain\Account\Nick('victim')));
        self::assertSame(['001AAAAAA: victim has been removed from the database.'], $this->irc->replies);
    }

    #[Test]
    public function anOperCanTakeOverAChannel(): void
    {
        $this->channels->seed('#taken', 'oldowner');

        $this->handler->handle($this->privmsg('APODERA #taken oper'), $this->state);

        self::assertSame('oper', $this->channels->find(new \Dbots\Domain\Channel\ChannelName('#taken'))?->founder);
        self::assertSame(['001AAAAAA: #taken now belongs to oper.'], $this->irc->replies);
    }

    #[Test]
    public function aNonOperCannotUseAdminCommands(): void
    {
        $this->handler->handle($this->privmsg('DROPNDB victim', '001BBBBBB'), $this->state);

        self::assertSame(['001BBBBBB: This command is restricted to IRC operators.'], $this->irc->replies);
    }

    #[Test]
    public function itListsClonesSharingAHost(): void
    {
        $this->handler->handle($this->privmsg('LISTCLONES victim'), $this->state);

        self::assertStringContainsString('clone.example', $this->irc->replies[0]);
        self::assertStringContainsString('victim, clone2', $this->irc->replies[1]);
    }

    #[Test]
    public function killclonesBansTheHostForAnHour(): void
    {
        $this->handler->handle($this->privmsg('KILLCLONES victim flooding'), $this->state);

        self::assertSame(['*@clone.example|3600|flooding'], $this->enforcement->glines);
    }

    #[Test]
    public function blockBansTheHostForFiveHours(): void
    {
        $this->handler->handle($this->privmsg('BLOCK victim spamming'), $this->state);

        self::assertSame(['*@clone.example|18000|spamming'], $this->enforcement->glines);
    }

    #[Test]
    public function itRefusesToBanAProtectedHost(): void
    {
        $this->state->introduceUser('001DDDDDD', 'localuser', null, 'i', 'localhost');

        $this->handler->handle($this->privmsg('BLOCK localuser bye'), $this->state);

        self::assertSame([], $this->enforcement->glines);
        self::assertStringContainsString('protected host', end($this->irc->replies));
    }

    #[Test]
    public function rootAuthenticationGatesRootCommands(): void
    {
        $handler = $this->rootedHandler('secreto');

        $handler->handle($this->privmsg('SETTIME'), $this->state);
        self::assertSame(['001AAAAAA: This command requires root access. Use ROOT <password>.'], $this->irc->replies);

        $this->irc->replies = [];
        $handler->handle($this->privmsg('ROOT secreto'), $this->state);
        self::assertSame(['001AAAAAA: You are now identified as root.'], $this->irc->replies);

        $this->irc->replies = [];
        $handler->handle($this->privmsg('SETTIME'), $this->state);
        self::assertSame(['001AAAAAA: Network time resynchronised.'], $this->irc->replies);
        self::assertCount(1, $this->enforcement->times);
    }

    #[Test]
    public function aWrongRootPasswordIsRejected(): void
    {
        $handler = $this->rootedHandler('secreto');

        $handler->handle($this->privmsg('ROOT nope'), $this->state);

        self::assertSame(['001AAAAAA: Incorrect password.'], $this->irc->replies);
    }

    #[Test]
    public function limpiaclearsTheCommonModes(): void
    {
        $this->handler->handle($this->privmsg('LIMPIA #chan'), $this->state);

        self::assertSame(['#chan|-imnpst|'], $this->enforcement->modes);
    }

    #[Test]
    public function itIgnoresMessagesForAnotherService(): void
    {
        $this->handler->handle(new IrcMessage('001AAAAAA', 'PRIVMSG', ['ChanServ'], 'KILL victim'), $this->state);

        self::assertSame([], $this->enforcement->kills);
    }

    private function rootedHandler(string $password): OperServCommandHandler
    {
        return new OperServCommandHandler(
            $this->enforcement,
            $this->accounts,
            new \Dbots\Tests\Support\RecordingProjection(),
            $this->channels,
            new \Dbots\Tests\Support\RecordingChannelProjection(),
            new \Dbots\Tests\Support\InMemorySpamRuleRepository(),
            $this->spamProjection(),
            $this->irc,
            'OperServ',
            '003A00004',
            OperServHelp::create(),
            rootPassword: $password,
        );
    }

    private function spamProjection(): \Dbots\Udb\UdbSpamProjection
    {
        return new \Dbots\Udb\UdbSpamProjection(
            new \Dbots\Tests\Support\InMemorySpamRuleRepository(),
            new \Dbots\Udb\UdbAuthority(
                '003',
                'bots.test',
                new \Dbots\Udb\CompositeBlockProvider([]),
                new \Dbots\Tests\Support\RecordingTransport(),
                '0123456789abcdef',
            ),
        );
    }

    private function privmsg(string $text, string $uid = '001AAAAAA'): IrcMessage
    {
        return new IrcMessage($uid, 'PRIVMSG', ['OperServ'], $text);
    }
}

final class RecordingEnforcement implements EnforcementInterface
{
    /** @var list<string> */
    public array $kills = [];

    /** @var list<string> */
    public array $kicks = [];

    /** @var list<string> */
    public array $modes = [];

    /** @var list<string> */
    public array $glines = [];

    /** @var list<string> */
    public array $unglines = [];

    /** @var list<string> */
    public array $renames = [];

    /** @var list<int> */
    public array $times = [];

    public function kill(string $uid, string $reason): void
    {
        $this->kills[] = $uid . '|' . $reason;
    }

    public function kick(string $channel, string $uid, string $reason): void
    {
        $this->kicks[] = $channel . '|' . $uid . '|' . $reason;
    }

    public function channelMode(string $channel, string $modes, ?string $target = null): void
    {
        $this->modes[] = $channel . '|' . $modes . '|' . ($target ?? '');
    }

    public function gline(string $userMask, string $hostMask, int $durationSeconds, string $reason): void
    {
        $this->glines[] = $userMask . '@' . $hostMask . '|' . $durationSeconds . '|' . $reason;
    }

    public function ungline(string $userMask, string $hostMask): void
    {
        $this->unglines[] = $userMask . '@' . $hostMask;
    }

    public function rename(string $uid, string $newNick): void
    {
        $this->renames[] = $uid . '->' . $newNick;
    }

    public function setTime(int $timestamp): void
    {
        $this->times[] = $timestamp;
    }
}
