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
    public function itMirrorsTheRawLineWithDateAndTime(): void
    {
        $this->relay->line(':001 PRIVMSG 003A00001 :STATUS JuanJo');

        self::assertCount(1, $this->transport->written);
        self::assertMatchesRegularExpression(
            '/^:003A00005 PRIVMSG #DeBuG :\(\d{2}\/\d{2}\/\d{4} - \d{2}:\d{2}\) => :001 PRIVMSG 003A00001 :STATUS JuanJo$/',
            $this->transport->written[0],
        );
    }

    #[Test]
    public function itHidesTheArgumentsOfCommandsThatCarryASecret(): void
    {
        $this->relay->line(':001 PRIVMSG 003A00001 :REGISTER miClave correo@ejemplo.org');

        self::assertStringContainsString('REGISTER <argumentos ocultos>', $this->transport->written[0]);
        self::assertStringNotContainsString('miClave', $this->transport->written[0]);
    }

    #[Test]
    public function itHidesPasswordRecords(): void
    {
        $this->relay->line(':004 DB * INS 0123456789abcdef 3 N::JuanJo::pass :!sY3J5cHQ6JDJ5JDEwJGFiYw==');

        self::assertStringNotContainsString('Y3J5cHQ6', $this->transport->written[0]);
        self::assertStringContainsString('<redacted>', $this->transport->written[0]);
    }

    #[Test]
    public function itStaysQuietWhenNoChannelIsConfigured(): void
    {
        $relay = new DebugRelay($this->transport, '003A00005', '');

        $relay->line(':001 PRIVMSG 003A00001 :STATUS JuanJo');

        self::assertSame([], $this->transport->written);
    }
}
