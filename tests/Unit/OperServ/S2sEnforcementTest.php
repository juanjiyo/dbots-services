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
}
