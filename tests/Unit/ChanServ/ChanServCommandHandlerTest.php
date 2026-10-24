<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\ChanServ;

use Dbots\ChanServ\Adapter\In\Irc\ChanServCommandHandler;
use Dbots\ChanServ\Application\DropChannel\DropChannelHandler;
use Dbots\ChanServ\Application\Port\Out\ChannelProjectionInterface;
use Dbots\ChanServ\Application\RegisterChannel\RegisterChannelHandler;
use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\NetworkState;
use Dbots\Domain\Account\Account;
use Dbots\Domain\Account\Nick;
use Dbots\Irc\Application\AccountResolver;
use Dbots\Tests\Support\InMemoryAccountRepository;
use Dbots\Tests\Support\InMemoryChannelAccessRepository;
use Dbots\Tests\Support\RecordingChannelProjection;
use Dbots\Tests\Support\InMemoryChannelRepository;
use Dbots\Tests\Support\RecordingIrc;
use Dbots\ChanServ\Application\Help\ChanServHelp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChanServCommandHandlerTest extends TestCase
{
    private InMemoryChannelRepository $channels;
    private InMemoryAccountRepository $accounts;
    private RecordingIrc $irc;
    private RecordingChannelProjection $projection;
    private ChanServCommandHandler $handler;
    private NetworkState $state;

    protected function setUp(): void
    {
        $this->channels = new InMemoryChannelRepository();
        $this->accounts = new InMemoryAccountRepository();
        $this->accounts->save(new Account(new Nick('alice'), 'hash', null, new \DateTimeImmutable()));
        $this->accounts->save(new Account(new Nick('bob'), 'hash', null, new \DateTimeImmutable()));
        $this->irc = new RecordingIrc();
        $this->projection = new RecordingChannelProjection();
        $this->handler = new ChanServCommandHandler(
            new RegisterChannelHandler($this->channels),
            new DropChannelHandler($this->channels, new InMemoryChannelAccessRepository()),
            $this->channels,
            new InMemoryChannelAccessRepository(),
            $this->projection,
            new AccountResolver($this->accounts),
            $this->irc,
            'ChanServ',
            '003A00002',
            ChanServHelp::create(),
        );
        $this->state = new NetworkState();
        $this->state->introduceUser('001AAAAAA', 'alice', null, 'r');
    }

    #[Test]
    public function itRegistersAChannelForAnIdentifiedUser(): void
    {
        $this->handler->handle($this->privmsg('register #SitioChat'), $this->state);

        self::assertNotNull($this->channels->find(new \Dbots\Domain\Channel\ChannelName('#sitiochat')));
        self::assertSame(['#SitioChat'], $this->projection->registered);
        self::assertSame(['001AAAAAA: #SitioChat is now registered to you.'], $this->irc->replies);
    }

    #[Test]
    public function itRefusesWhenTheSenderIsNotIdentified(): void
    {
        $state = new NetworkState();
        $state->introduceUser('001BBBBBB', 'bob');

        $this->handler->handle($this->privmsg('register #Test', '001BBBBBB'), $state);

        self::assertSame([], $this->channels->all());
        self::assertSame(['001BBBBBB: You must be identified to register a channel.'], $this->irc->replies);
    }

    #[Test]
    public function itRejectsADuplicateChannel(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->handle($this->privmsg('register #test'), $this->state);

        self::assertSame(['001AAAAAA: #test is already registered.'], $this->irc->replies);
        self::assertSame([], $this->projection->registered);
    }

    #[Test]
    public function itRejectsAnInvalidChannelName(): void
    {
        $this->handler->handle($this->privmsg('register notachannel'), $this->state);

        self::assertSame(['001AAAAAA: That channel name cannot be registered.'], $this->irc->replies);
    }

    #[Test]
    public function theFounderCanDropTheirChannel(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->handle($this->privmsg('drop #test'), $this->state);

        self::assertNull($this->channels->find(new \Dbots\Domain\Channel\ChannelName('#test')));
        self::assertSame(['#test'], $this->projection->dropped);
        self::assertSame(['001AAAAAA: #test has been dropped.'], $this->irc->replies);
    }

    #[Test]
    public function aStrangerCannotDropAChannel(): void
    {
        $this->channels->seed('#Test', 'alice');
        $state = new NetworkState();
        $state->introduceUser('001BBBBBB', 'bob', null, 'r');

        $this->handler->handle($this->privmsg('drop #test', '001BBBBBB'), $state);

        self::assertNotNull($this->channels->find(new \Dbots\Domain\Channel\ChannelName('#test')));
        self::assertSame(['001BBBBBB: Only the founder can drop a channel.'], $this->irc->replies);
    }

    #[Test]
    public function itReportsChannelInformation(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->handle($this->privmsg('info #test'), $this->state);

        self::assertCount(1, $this->irc->replies);
        self::assertStringContainsString('#test is registered to alice', $this->irc->replies[0]);
    }

    #[Test]
    public function theFounderCanGrantAndRevokeAccess(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->handle($this->privmsg('access add #test bob'), $this->state);
        self::assertSame(['#test:bob'], $this->projection->accessAdded);

        $this->handler->handle($this->privmsg('access list #test'), $this->state);
        self::assertStringContainsString('bob', end($this->irc->replies));

        $this->handler->handle($this->privmsg('access del #test bob'), $this->state);
        self::assertSame(['#test:bob'], $this->projection->accessRemoved);
    }

    #[Test]
    public function aStrangerCannotGrantAccess(): void
    {
        $this->channels->seed('#Test', 'alice');
        $state = new NetworkState();
        $state->introduceUser('001BBBBBB', 'bob', null, 'r');

        $this->handler->handle($this->privmsg('access add #test bob', '001BBBBBB'), $state);

        self::assertSame([], $this->projection->accessAdded);
        self::assertSame(['001BBBBBB: Only the founder can do that.'], $this->irc->replies);
    }

    #[Test]
    public function theFounderCanSetTheTopic(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->handle($this->privmsg('topic #test welcome home'), $this->state);

        self::assertSame(['#test'], $this->projection->topics);
        self::assertSame('welcome home', $this->channels->find(new \Dbots\Domain\Channel\ChannelName('#test'))?->topic);
    }

    #[Test]
    public function itIgnoresMessagesForAnotherService(): void
    {
        $message = new IrcMessage('001AAAAAA', 'PRIVMSG', ['NickServ'], 'register #test');

        $this->handler->handle($message, $this->state);

        self::assertSame([], $this->irc->replies);
    }

    private function privmsg(string $text, string $uid = '001AAAAAA'): IrcMessage
    {
        return new IrcMessage($uid, 'PRIVMSG', ['ChanServ'], $text);
    }
}

