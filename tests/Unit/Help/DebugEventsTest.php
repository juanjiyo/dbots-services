<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\Help;

use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\DebugEvents;
use Dbots\Irc\Application\Help\IrcFormat;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DebugEventsTest extends TestCase
{
    #[Test]
    public function anIntroductionShowsTheServerThatIntroducedTheUser(): void
    {
        $message = new IrcMessage('001', 'UID', ['Stable', '0', '1', 'db', 'localhost', '001ABC123', '0', '+iwxT', 'v', 'c', 'ip'], 'db');

        $line = IrcFormat::strip((string) DebugEvents::translate($message, static fn (string $uid): ?string => 'Stable'));

        self::assertStringContainsString('NICK: Stable', $line);
        self::assertStringContainsString('SERVIDOR: 001', $line);
        self::assertStringNotContainsString('001ABC123', $line);
    }

    #[Test]
    public function aSingleUserJoiningReadsInSingular(): void
    {
        $message = new IrcMessage('001', 'SJOIN', ['1', '#demo', '+nt'], '001ABC123');

        $line = IrcFormat::strip((string) DebugEvents::translate($message, static fn (string $uid): ?string => 'Stable'));

        self::assertStringContainsString('Stable entra al canal #demo', $line);
        self::assertStringNotContainsString('entra(n)', $line);
    }

    #[Test]
    public function severalUsersJoiningReadsInPlural(): void
    {
        $message = new IrcMessage('001', 'SJOIN', ['1', '#demo', '+nt'], '001ABC123 001DEF456');

        $line = IrcFormat::strip((string) DebugEvents::translate(
            $message,
            static fn (string $uid): ?string => '001ABC123' === $uid ? 'Uno' : 'Dos',
        ));

        self::assertStringContainsString('Uno, Dos entran al canal #demo', $line);
    }

    #[Test]
    public function aQuitResolvesTheNickThroughTheResolver(): void
    {
        $message = new IrcMessage('001ABC123', 'QUIT', [], 'Connection closed');

        $line = IrcFormat::strip((string) DebugEvents::translate($message, static fn (string $uid): ?string => 'Stable'));

        self::assertStringContainsString('NICK: Stable', $line);
        self::assertStringContainsString('Connection closed', $line);
    }
}
