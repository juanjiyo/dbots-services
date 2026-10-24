<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\OperServ;

use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\NetworkState;
use Dbots\OperServ\Adapter\In\Irc\OperServCommandHandler;
use Dbots\OperServ\Application\Port\Out\EnforcementInterface;
use Dbots\Tests\Support\RecordingIrc;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OperServCommandHandlerTest extends TestCase
{
    private RecordingEnforcement $enforcement;
    private RecordingIrc $irc;
    private OperServCommandHandler $handler;
    private NetworkState $state;

    protected function setUp(): void
    {
        $this->enforcement = new RecordingEnforcement();
        $this->irc = new RecordingIrc();
        $this->handler = new OperServCommandHandler(
            $this->enforcement,
            $this->irc,
            'OperServ',
            '003A00004',
        );
        $this->state = new NetworkState();
        $this->state->introduceUser('001AAAAAA', 'oper', null, 'o');
        $this->state->introduceUser('001BBBBBB', 'victim', null, 'i');
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

        self::assertSame(['001AAAAAA: Users online: 2.'], $this->irc->replies);
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
    public function itIgnoresMessagesForAnotherService(): void
    {
        $this->handler->handle(new IrcMessage('001AAAAAA', 'PRIVMSG', ['ChanServ'], 'KILL victim'), $this->state);

        self::assertSame([], $this->enforcement->kills);
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
}
