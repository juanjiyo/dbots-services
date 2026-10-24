<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\NickServ;

use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\NetworkState;
use Dbots\NickServ\Adapter\In\Irc\NickServCommandHandler;
use Dbots\ChanServ\Application\Port\Out\ChannelProjectionInterface;
use Dbots\ChanServ\Application\Port\Out\ChannelRepositoryInterface;
use Dbots\ChanServ\Application\Port\Out\ChannelRepositoryInterface as Channels;
use Dbots\Domain\Account\Account;
use Dbots\Domain\Account\Nick;
use Dbots\Domain\Channel\Channel;
use Dbots\Domain\Channel\ChannelName;
use Dbots\Irc\Application\AccountResolver;
use Dbots\NickServ\Application\AccountInfo\AccountInfoHandler;
use Dbots\NickServ\Application\ChangePassword\ChangePasswordHandler;
use Dbots\NickServ\Application\DropNick\DropNickHandler;
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
    private RecordingProjection $projection;
    private InMemoryAccountRepository $accounts;
    private NickServCommandHandler $handler;
    private NetworkState $state;

    protected function setUp(): void
    {
        $accounts = new InMemoryAccountRepository();
        $this->accounts = $accounts;
        $this->irc = new RecordingIrc();
        $this->projection = new RecordingProjection();
        $this->handler = new NickServCommandHandler(
            new RegisterNickHandler($accounts),
            new IdentifyHandler($accounts),
            $this->irc,
            $this->projection,
            new ChangePasswordHandler($accounts),
            new DropNickHandler($accounts, new NoChannels()),
            new AccountResolver($accounts),
            new AccountInfoHandler($accounts, new NoChannels(), new \Dbots\Tests\Support\InMemoryMemoRepository()),
            $accounts,
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

        self::assertSame(['001AAAAAA: Nickname registered. Identify with: /NICK JuanJo:<password>'], $this->irc->replies);
    }

    #[Test]
    public function itIdentifiesWithTheRightPassword(): void
    {
        $this->handler->handle($this->privmsg('register hunter2'), $this->state);
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('IDENTIFY hunter2'), $this->state);

        self::assertSame(['001AAAAAA: Password correct. Identify with: /NICK JuanJo:<password>'], $this->irc->replies);
    }

    #[Test]
    public function itRejectsAWrongPassword(): void
    {
        $this->handler->handle($this->privmsg('register hunter2'), $this->state);
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('identify nope'), $this->state);

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

    #[Test]
    public function itChangesThePasswordWhenTheCurrentOneMatches(): void
    {
        $this->handler->handle($this->privmsg('register hunter2'), $this->state);
        $this->state->applyModeChange('001AAAAAA', '+r');
        $this->irc->replies = [];
        $this->projection->published = [];

        $this->handler->handle($this->privmsg('set password hunter2 nuevaclave'), $this->state);

        self::assertSame(['001AAAAAA: Your password has been changed.'], $this->irc->replies);
        self::assertSame(['JuanJo'], $this->projection->published);
        self::assertSame(IdentifyResultForTest::NewPasswordWorks, $this->identifyWith('nuevaclave'));
    }

    #[Test]
    public function itRejectsAWrongCurrentPassword(): void
    {
        $this->handler->handle($this->privmsg('register hunter2'), $this->state);
        $this->state->applyModeChange('001AAAAAA', '+r');
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('set password wrongpass nuevaclave'), $this->state);

        self::assertSame(['001AAAAAA: The current password is not correct.'], $this->irc->replies);
    }

    #[Test]
    public function itRequiresIdentificationToChangeThePassword(): void
    {
        $this->handler->handle($this->privmsg('register hunter2'), $this->state);
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('set password hunter2 nuevaclave'), $this->state);

        self::assertSame(['001AAAAAA: You must be identified to change your password.'], $this->irc->replies);
    }

    #[Test]
    public function itDropsTheAccountWithTheRightPassword(): void
    {
        $this->handler->handle($this->privmsg('register hunter2'), $this->state);
        $this->state->applyModeChange('001AAAAAA', '+r');
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('drop hunter2'), $this->state);

        self::assertSame(['001AAAAAA: Your account has been dropped.'], $this->irc->replies);
        self::assertSame(['JuanJo'], $this->projection()->removed);
    }

    #[Test]
    public function itRefusesToDropAnAccountThatStillOwnsChannels(): void
    {
        $this->handler->handle($this->privmsg('register hunter2'), $this->state);
        $this->state->applyModeChange('001AAAAAA', '+r');
        $this->irc->replies = [];

        $handler = $this->handlerWithChannels(new NoChannels('#test', 'JuanJo'));
        $handler->handle($this->privmsg('drop hunter2'), $this->state);

        self::assertSame(['001AAAAAA: Drop your registered channels first.'], $this->irc->replies);
    }

    private function identifyWith(string $password): IdentifyResultForTest
    {
        $result = (new IdentifyHandler($this->accounts))( 'JuanJo', $password);

        return IdentifyResultForTest::NewPasswordWorks === $this->map($result) ? IdentifyResultForTest::NewPasswordWorks : IdentifyResultForTest::Other;
    }

    private function map(\Dbots\NickServ\Application\Identify\IdentifyResult $result): IdentifyResultForTest
    {
        return \Dbots\NickServ\Application\Identify\IdentifyResult::Identified === $result
            ? IdentifyResultForTest::NewPasswordWorks
            : IdentifyResultForTest::Other;
    }

    private function projection(): RecordingProjection
    {
        return $this->projection;
    }

    private function handlerWithChannels(ChannelRepositoryInterface $channels): NickServCommandHandler
    {
        return new NickServCommandHandler(
            new RegisterNickHandler($this->accounts),
            new IdentifyHandler($this->accounts),
            $this->irc,
            $this->projection,
            new ChangePasswordHandler($this->accounts),
            new DropNickHandler($this->accounts, $channels),
            new AccountResolver($this->accounts),
            new AccountInfoHandler($this->accounts, $channels, new \Dbots\Tests\Support\InMemoryMemoRepository()),
            $this->accounts,
            'NickServ',
            '003A00001',
        );
    }

    #[Test]
    public function anOperCanForbidAndUnforbid(): void
    {
        $this->handler->handle($this->privmsg('register hunter2'), $this->state);
        $oper = $this->operState();
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('FORBID JuanJo breaking rules', '001AAAAAA'), $oper);
        self::assertSame(['001AAAAAA: JuanJo is now forbidden.'], $this->irc->replies);
        self::assertTrue($this->accounts->findByNick(new Nick('JuanJo'))?->isForbidden());

        $this->irc->replies = [];
        $this->handler->handle($this->privmsg('UNFORBID JuanJo', '001AAAAAA'), $oper);
        self::assertFalse($this->accounts->findByNick(new Nick('JuanJo'))?->isForbidden());
    }

    #[Test]
    public function nonOpersCannotForbid(): void
    {
        $this->handler->handle($this->privmsg('register hunter2'), $this->state);
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('FORBID JuanJo nope'), $this->state);

        self::assertSame(['001AAAAAA: This command is restricted to IRC operators.'], $this->irc->replies);
        self::assertFalse($this->accounts->findByNick(new Nick('JuanJo'))?->isForbidden());
    }

    #[Test]
    public function anOperCanSuspendAndStatusReportsIt(): void
    {
        $this->handler->handle($this->privmsg('register hunter2'), $this->state);
        $oper = $this->operState();

        $this->handler->handle($this->privmsg('SUSPEND JuanJo abuse', '001AAAAAA'), $oper);
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('STATUS JuanJo'), $oper);

        self::assertStringContainsString('suspended', $this->irc->replies[0]);
    }

    private function operState(): NetworkState
    {
        $state = new NetworkState();
        $state->introduceUser('001AAAAAA', 'JuanJo', null, 'ro');

        return $state;
    }

    private function privmsg(string $text, string $uid = '001AAAAAA'): IrcMessage
    {
        return new IrcMessage('001AAAAAA', 'PRIVMSG', ['NickServ'], $text);
    }
}


enum IdentifyResultForTest
{
    case NewPasswordWorks;
    case Other;
}

final class NoChannels implements ChannelRepositoryInterface
{
    public function __construct(
        private ?string $name = null,
        private ?string $founder = null,
    ) {}

    public function find(ChannelName $name): ?Channel
    {
        return null === $this->name ? null : new Channel(new ChannelName($this->name), (string) $this->founder, new \DateTimeImmutable());
    }

    public function save(Channel $channel): void {}

    public function updateTopic(Channel $channel): void {}

    public function updateFounder(Channel $channel): void {}

    public function delete(ChannelName $name): void {}

    public function all(): array
    {
        return null === $this->name ? [] : [new Channel(new ChannelName($this->name), (string) $this->founder, new \DateTimeImmutable())];
    }
}
