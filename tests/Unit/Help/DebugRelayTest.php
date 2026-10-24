<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\Help;

use Dbots\Irc\Application\DebugRelay;
use Dbots\Tests\Support\RecordingTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DebugRelayTest extends TestCase
{
    private RecordingTransport $transport;
    private DebugRelay $relay;

    protected function setUp(): void
    {
        $this->transport = new RecordingTransport();
        $this->relay = new DebugRelay($this->transport, '003A00005', '#DeBuG');
    }

    #[Test]
    public function aCommandIsRenderedWithTheChannelFormat(): void
    {
        $this->relay->command('JuanJo', 'STATUS', 'NickServ');

        $line = $this->transport->written[0];

        self::assertStringStartsWith(':003A00005 PRIVMSG #DeBuG :', $line);
        // The <DeBuG> the channel shows is the client's nickname, not our text.
        self::assertStringNotContainsString('<DeBuG>', $line);
        self::assertStringContainsString("\x0312JuanJo", $line);
        self::assertStringContainsString('STATUS en NickServ', $line);
    }

    #[Test]
    public function theArgumentsOfSecretCommandsAreHidden(): void
    {
        $this->relay->command('JuanJo', 'IDENTIFY', 'NickServ', 'miClave');

        self::assertStringNotContainsString('miClave', $this->transport->written[0]);
        self::assertStringContainsString('IDENTIFY en NickServ', $this->transport->written[0]);
    }

    #[Test]
    public function ordinaryCommandsKeepTheirArguments(): void
    {
        $this->relay->command('JuanJo', 'STATUS', 'NickServ', 'otroNick');

        self::assertStringContainsString('STATUS otroNick', $this->transport->written[0]);
    }

    #[Test]
    public function aMutationIsTranslatedIntoASentence(): void
    {
        $this->relay->mutation(':004 DB * INS 0123456789abcdef 3 N::JuanJo::pass :!sAAAA');

        $line = $this->transport->written[0];

        self::assertStringContainsString('NickServ registra o cambia la clave de JuanJo', $line);
        self::assertStringNotContainsString('!sAAAA', $line);
    }

    #[Test]
    public function aForbidReadsLikeTheChannelSays(): void
    {
        $this->relay->mutation(':004 DB * INS 0123456789abcdef 4 N::JuanJo::forbid :!sAAAA');

        self::assertStringContainsString('NickServ añade la prohibición de JuanJo', $this->transport->written[0]);
    }

    #[Test]
    public function itStaysQuietWhenNoChannelIsConfigured(): void
    {
        $relay = new DebugRelay($this->transport, '003A00005', '');

        $relay->command('JuanJo', 'STATUS', 'NickServ');
        $relay->mutation(':004 DB * INS 1 1 N::X::pass :!sAAAA');

        self::assertSame([], $this->transport->written);
    }
}
