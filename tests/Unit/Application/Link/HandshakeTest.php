<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\Application\Link;

use Dbots\Application\Link\Handshake;
use Dbots\Domain\Link\LinkState;
use Dbots\Domain\Link\ServerLink;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HandshakeTest extends TestCase
{
    private Handshake $handshake;

    protected function setUp(): void
    {
        $this->handshake = new Handshake(new ServerLink('bots.example.org', '003', 'secret', 'dbots test'));
    }

    #[Test]
    public function itStartsWithPassEauthCapabilitiesAndServerInThatOrder(): void
    {
        $lines = $this->handshake->start();

        self::assertCount(4, $lines);
        self::assertSame('PASS :secret', $lines[0]);
        self::assertSame('PROTOCTL EAUTH=bots.example.org SID=003', $lines[1]);
        self::assertStringStartsWith('PROTOCTL NOQUIT', $lines[2]);
        self::assertSame('SERVER bots.example.org 1 :dbots test', $lines[3]);
        self::assertSame(LinkState::Handshaking, $this->handshake->state());
    }

    #[Test]
    public function itRefusesToStartTwice(): void
    {
        $this->handshake->start();

        $this->expectException(LogicException::class);

        $this->handshake->start();
    }

    #[Test]
    public function itAnswersPing(): void
    {
        $this->handshake->start();

        $this->handshake->consume('PING :abc123');

        self::assertSame([':003 PONG :abc123'], $this->handshake->flush());
    }

    #[Test]
    public function itAnswersNetinfoWithItsOwnNetworkName(): void
    {
        $this->handshake->start();

        $this->handshake->consume('NETINFO 1 1700000000 6100 SHA256:aa 0 0 0 :ExampleNet');

        $lines = $this->handshake->flush();

        self::assertCount(1, $lines);
        self::assertMatchesRegularExpression('/^NETINFO 0 \d+ 6100 \* 0 0 0 :IRC Network$/', $lines[0]);
    }

    #[Test]
    public function itCapturesTheRemoteSidFromProtoctl(): void
    {
        $this->handshake->start();

        $this->handshake->consume('PROTOCTL CHANMODES=beI,fkL,lFH PREFIX=(qaohv)~&@%+ SID=001 MLOCK');

        self::assertSame('001', $this->handshake->remoteSid());
    }

    #[Test]
    public function itSyncsOnThePeerEos(): void
    {
        $this->handshake->start();
        $this->handshake->consume('PROTOCTL SID=001');

        $this->handshake->consume(':001 EOS');

        self::assertSame(LinkState::Synced, $this->handshake->state());
        self::assertSame([':003 EOS'], $this->handshake->flush());
    }

    #[Test]
    public function itIgnoresARelayedEosFromAnotherServer(): void
    {
        $this->handshake->start();
        $this->handshake->consume('PROTOCTL SID=001');

        $this->handshake->consume(':002 EOS');

        self::assertSame(LinkState::Handshaking, $this->handshake->state());
        self::assertSame([], $this->handshake->flush());
    }

    #[Test]
    public function itFailsOnAnErrorLine(): void
    {
        $this->handshake->start();

        $this->handshake->consume('ERROR :Link denied (No matching link configuration)');

        self::assertSame(LinkState::Failed, $this->handshake->state());
        self::assertSame('Link denied (No matching link configuration)', $this->handshake->error());
    }

    #[Test]
    public function itDoesNotSyncAfterFailure(): void
    {
        $this->handshake->start();
        $this->handshake->consume('ERROR :Rejected');

        $this->handshake->consume(':001 EOS');

        self::assertSame(LinkState::Failed, $this->handshake->state());
        self::assertSame([], $this->handshake->flush());
    }
}
