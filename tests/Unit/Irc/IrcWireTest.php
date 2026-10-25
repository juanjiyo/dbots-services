<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\Irc;

use Dbots\Irc\Adapter\In\IrcMessageParser;
use Dbots\Irc\Application\NetworkState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IrcWireTest extends TestCase
{
    private IrcMessageParser $parser;

    protected function setUp(): void
    {
        $this->parser = new IrcMessageParser();
    }

    #[Test]
    public function itParsesAPrivateMessageWithTrailing(): void
    {
        $message = $this->parser->parse(':001AAAAAA PRIVMSG NickServ :register hunter2');

        self::assertNotNull($message);
        self::assertSame('001AAAAAA', $message->source());
        self::assertSame('PRIVMSG', $message->command);
        self::assertSame('NickServ', $message->target());
        self::assertSame('register hunter2', $message->text());
    }

    #[Test]
    public function aJoinKeepsTheChannelInTheTrailingPart(): void
    {
        $message = $this->parser->parse(':001AAAAAA!db@host JOIN :#clavedemo2');

        self::assertNotNull($message);
        self::assertSame([], $message->params);
        self::assertSame('#clavedemo2', $message->firstParameter());
    }

    #[Test]
    public function aPartWithAParameterStillResolvesTheChannel(): void
    {
        $message = $this->parser->parse(':001AAAAAA!db@host PART #clavedemo2 :bye');

        self::assertNotNull($message);
        self::assertSame('#clavedemo2', $message->firstParameter());
    }

    #[Test]
    public function itParsesAUserIntroductionFromALiveBurst(): void
    {
        $message = $this->parser->parse(
            ':002 UID NiCK 0 1792591497 NickServ services.sitiochat.es 002A00001 0 +doqIS services.sitiochat.es Clk-A7D2C989.sitiochat.es * :Nickname Registration Services'
        );

        self::assertNotNull($message);
        self::assertSame('UID', $message->command);
        self::assertSame('NiCK', $message->params[0]);
        self::assertSame('002A00001', $message->params[5]);
    }

    #[Test]
    public function itStripsMessageTags(): void
    {
        $message = $this->parser->parse('@time=2026-10-24T07:00:00.000Z :001AAAAAA PRIVMSG NickServ :hi');

        self::assertNotNull($message);
        self::assertSame('PRIVMSG', $message->command);
        self::assertSame('hi', $message->text());
    }

    #[Test]
    public function itReturnsNullForBlankLines(): void
    {
        self::assertNull($this->parser->parse('   '));
    }

    #[Test]
    public function theNetworkStateTracksIntroductionsRenamesAndQuits(): void
    {
        $state = new NetworkState();

        $state->introduceUser('001AAAAAA', 'JuanJo');
        self::assertSame('JuanJo', $state->nickOf('001AAAAAA'));
        self::assertSame('001AAAAAA', $state->uidOf('juanjo'));

        $state->rename('001AAAAAA', 'JuanJo_');
        self::assertSame('JuanJo_', $state->nickOf('001AAAAAA'));
        self::assertNull($state->uidOf('JuanJo'));

        $state->remove('001AAAAAA');
        self::assertNull($state->nickOf('001AAAAAA'));
        self::assertSame(0, $state->count());
    }

    #[Test]
    public function itTracksIdentificationThroughModeChanges(): void
    {
        $state = new NetworkState();
        $state->introduceUser('001AAAAAA', 'alice', null, 'iwxT');

        self::assertFalse($state->hasMode('001AAAAAA', 'r'));

        // UnrealIRCd sends the identified flag after the introduction.
        $state->applyModeChange('001AAAAAA', '+r');
        self::assertTrue($state->hasMode('001AAAAAA', 'r'));

        $state->applyModeChange('001AAAAAA', '-r+b');
        self::assertFalse($state->hasMode('001AAAAAA', 'r'));
        self::assertTrue($state->hasMode('001AAAAAA', 'b'));
        self::assertTrue($state->hasMode('001AAAAAA', 'i'), 'existing modes survive');
    }

    #[Test]
    public function itRecordsAndClearsAccounts(): void
    {
        $state = new NetworkState();
        $state->introduceUser('001AAAAAA', 'alice');

        self::assertNull($state->accountOf('001AAAAAA'));

        $state->setAccount('001AAAAAA', 'alice');
        self::assertSame('alice', $state->accountOf('001AAAAAA'));

        $state->setAccount('001AAAAAA', null);
        self::assertNull($state->accountOf('001AAAAAA'));
    }
}
