<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\NickServ;

use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\NetworkState;
use Dbots\NickServ\Adapter\In\Irc\NickServCommandHandler;
use Dbots\NickServ\Application\Identify\IdentifyHandler;
use Dbots\NickServ\Application\RegisterNick\RegisterNickHandler;
use Dbots\Tests\Support\InMemoryAccountRepository;
use Dbots\Tests\Support\RecordingIrc;
use Dbots\Tests\Support\RecordingProjection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NickServCommandHandlerTest extends TestCase
{
    private RecordingIrc $irc;
    private NickServCommandHandler $handler;
    private NetworkState $state;

    protected function setUp(): void
    {
        $accounts = new InMemoryAccountRepository();
        $this->irc = new RecordingIrc();
        $this->handler = new NickServCommandHandler(
            new RegisterNickHandler($accounts),
            new IdentifyHandler($accounts),
            $this->irc,
            $this->irc,
            new RecordingProjection(),
            'NickServ',
            '003A00001',
        );
        $this->state = new NetworkState();
        $this->state->introduceUser('001AAAAAA', 'JuanJo');
    }

    #[Test]
    public function itRegistersAndLogsTheUserIn(): void
    {
        $this->handler->handle($this->privmsg('register hunter2'), $this->state);

        self::assertSame(['001AAAAAA:JuanJo'], $this->irc->logins);
        self::assertSame(['001AAAAAA: Nickname registered. You are now identified.'], $this->irc->replies);
    }

    #[Test]
    public function itIdentifiesWithTheRightPassword(): void
    {
        $this->handler->handle($this->privmsg('register hunter2'), $this->state);
        $this->irc->logins = [];
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('IDENTIFY hunter2'), $this->state);

        self::assertSame(['001AAAAAA:JuanJo'], $this->irc->logins);
        self::assertSame(['001AAAAAA: Password accepted. You are now identified.'], $this->irc->replies);
    }

    #[Test]
    public function itRejectsAWrongPassword(): void
    {
        $this->handler->handle($this->privmsg('register hunter2'), $this->state);
        $this->irc->logins = [];
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('identify nope'), $this->state);

        self::assertSame([], $this->irc->logins);
        self::assertSame(['001AAAAAA: Invalid password.'], $this->irc->replies);
    }

    #[Test]
    public function itNeverEchoesThePassword(): void
    {
        $this->handler->handle($this->privmsg('identify hunter2'), $this->state);

        foreach ($this->irc->replies as $reply) {
            self::assertStringNotContainsString('hunter2', $reply);
        }
    }

    #[Test]
    public function itIgnoresMessagesAddressedToAnotherTarget(): void
    {
        $message = new IrcMessage('001AAAAAA', 'PRIVMSG', ['ChanServ'], 'register hunter2');

        $this->handler->handle($message, $this->state);

        self::assertSame([], $this->irc->replies);
        self::assertSame([], $this->irc->logins);
    }

    #[Test]
    public function itAnswersSyntaxErrors(): void
    {
        $this->handler->handle($this->privmsg('register'), $this->state);

        self::assertSame(['001AAAAAA: Syntax: REGISTER <password> [email]'], $this->irc->replies);
    }

    #[Test]
    public function itAnswersAnUnknownCommand(): void
    {
        $this->handler->handle($this->privmsg('banana'), $this->state);

        self::assertSame(['001AAAAAA: Unknown command. Use HELP to list the available commands.'], $this->irc->replies);
    }

    #[Test]
    public function itIgnoresUnknownSenders(): void
    {
        $message = new IrcMessage('001ZZZZZZ', 'PRIVMSG', ['NickServ'], 'register hunter2');

        $this->handler->handle($message, $this->state);

        self::assertSame([], $this->irc->replies);
    }

    private function privmsg(string $text): IrcMessage
    {
        return new IrcMessage('001AAAAAA', 'PRIVMSG', ['NickServ'], $text);
    }
}
