<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\Udb;

use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Udb\BlockProviderInterface;
use Dbots\Udb\NativeDigest;
use Dbots\Udb\UdbAuthority;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UdbAuthorityTest extends TestCase
{
    private const string EPOCH = '0123456789abcdef';

    /** @var list<string> */
    private array $written = [];

    private function authority(array $blocks = []): UdbAuthority
    {
        $this->written = [];

        $provider = new class($blocks) implements BlockProviderInterface {
            /** @param array<string, array<string, string|int|null>> $blocks */
            public function __construct(private array $blocks) {}

            public function records(string $block): array
            {
                return $this->blocks[$block] ?? [];
            }
        };

        return new UdbAuthority(
            '007',
            'bots.example.org',
            $provider,
            function (string $line): void {
                $this->written[] = $line;
            },
            self::EPOCH,
        );
    }

    #[Test]
    public function itAcknowledgesHelWithAFixedWidthEpoch(): void
    {
        $authority = $this->authority();

        $authority->handle($this->frame(['007', 'HEL', '4', '-', self::EPOCH, 'OCL']));

        self::assertSame([':007 DB 001 HEL 4 ACK bots.example.org 0123456789abcdef OCL'], $this->written);
    }

    #[Test]
    public function itAnnouncesSixInventoriesWhenSelected(): void
    {
        $authority = $this->authority();

        $authority->handle($this->frame(['007', 'HEL', '4', 'bots.example.org', self::EPOCH, 'OCL']));

        self::assertCount(7, $this->written);
        self::assertStringContainsString('HEL 4 ACK bots.example.org', $this->written[0]);

        $infLines = array_slice($this->written, 1);

        self::assertCount(6, $infLines);

        foreach (['N', 'C', 'I', 'S', 'L', 'K'] as $index => $block) {
            self::assertStringContainsString(" INF 1 {$block} ", $infLines[$index]);
        }

        self::assertStringContainsString(NativeDigest::logicalDigest('N', []), $infLines[0]);
    }

    #[Test]
    public function itAnnouncesOnlyOnce(): void
    {
        $authority = $this->authority();
        $frame = $this->frame(['007', 'HEL', '4', 'bots.example.org', self::EPOCH, 'OCL']);

        $authority->handle($frame);
        $count = count($this->written);
        $authority->handle($frame);

        self::assertSame($count + 1, count($this->written));
    }

    #[Test]
    public function itServesAnEmptyBlockOnRes(): void
    {
        $authority = $this->authority();
        $authority->handle($this->frame(['007', 'HEL', '4', 'bots.example.org', self::EPOCH, 'OCL']));
        $this->written = [];

        $authority->handle($this->frame(['007', 'RES', '1', 'N']));

        $digest = NativeDigest::logicalDigest('N', []);

        self::assertCount(2, $this->written);
        self::assertSame(":007 DB 001 BEGIN 1 N tx1N {$digest} 0", $this->written[0]);
        self::assertSame(":007 DB 001 END 1 N tx1N {$digest} 0", $this->written[1]);
    }

    #[Test]
    public function itServesRecordsWithTypedValues(): void
    {
        $authority = $this->authority([
            'N' => ['alice::pass' => 'crypt:$2y$hash'],
        ]);
        $authority->handle($this->frame(['007', 'HEL', '4', 'bots.example.org', self::EPOCH, 'OCL']));
        $this->written = [];

        $authority->handle($this->frame(['007', 'RES', '1', 'N']));

        self::assertCount(3, $this->written);
        self::assertStringContainsString('PUT 1 N tx1N N::alice::pass :!s', $this->written[1]);
    }

    #[Test]
    public function itPublishesAnInsertWithAMonotonicSequence(): void
    {
        $authority = $this->authority();

        $authority->insertPassword('alice', 'crypt:abc');
        $authority->insertPassword('bob', 'crypt:def');

        self::assertSame(2, $authority->sequence());
        self::assertStringStartsWith(':007 DB * INS 0123456789abcdef 1 N::alice::pass :!s', $this->written[0]);
        self::assertStringStartsWith(':007 DB * INS 0123456789abcdef 2 N::bob::pass :!s', $this->written[1]);
    }

    #[Test]
    public function itIgnoresFramesFromNonServerSources(): void
    {
        $authority = $this->authority();

        $authority->handle(new IrcMessage('001AAAAAA', 'DB', ['007', 'HEL', '4', '-', self::EPOCH, 'OCL'], null));

        self::assertSame([], $this->written);
    }

    /** @param list<string> $params */
    private function frame(array $params): IrcMessage
    {
        return new IrcMessage('001', 'DB', $params, null);
    }
}
