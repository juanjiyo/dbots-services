<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\OperServ;

use Dbots\OperServ\Adapter\Out\Irc\S2sEnforcement;
use Dbots\Tests\Support\RecordingTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class S2sEnforcementTest extends TestCase
{
    #[Test]
    public function itSendsAnUnrealircdSqlineToReserveANick(): void
    {
        $transport = new RecordingTransport();
        $enforcement = new S2sEnforcement($transport, '003');

        $enforcement->reserveNick('NiCK', 'Reservado para Servicios');

        self::assertSame([':003 SQLINE NiCK :Reservado para Servicios'], $transport->written);
    }

    #[Test]
    public function itSendsAShunCommandWithDurationString(): void
    {
        $transport = new RecordingTransport();
        $enforcement = new S2sEnforcement($transport, '003');

        $enforcement->shun('*@spammer.example.com', 900, 'Sumidero: flood');

        self::assertSame([':003 SHUN +*@spammer.example.com 15m :Sumidero: flood'], $transport->written);
    }

    #[Test]
    public function itSendsAnUnshunCommand(): void
    {
        $transport = new RecordingTransport();
        $enforcement = new S2sEnforcement($transport, '003');

        $enforcement->unshun('*@spammer.example.com');

        self::assertSame([':003 SHUN -*@spammer.example.com'], $transport->written);
    }

    #[Test]
    public function itFallsBackToSecondsWhenDurationDoesNotDivideNicely(): void
    {
        $transport = new RecordingTransport();
        $enforcement = new S2sEnforcement($transport, '003');

        $enforcement->shun('*@host.example.com', 95, 'test');

        self::assertSame([':003 SHUN +*@host.example.com 95s :test'], $transport->written);
    }
}
