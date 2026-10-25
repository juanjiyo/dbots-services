<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\Udb;

use DateTimeImmutable;
use Dbots\Domain\Channel\Channel;
use Dbots\Domain\Channel\ChannelName;
use Dbots\Tests\Support\InMemoryChannelRepository;
use Dbots\Udb\ChannelBlockProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChannelBlockProviderTest extends TestCase
{
    private InMemoryChannelRepository $channels;
    private ChannelBlockProvider $provider;

    protected function setUp(): void
    {
        $this->channels = new InMemoryChannelRepository();
        $this->provider = new ChannelBlockProvider($this->channels, new \Dbots\Tests\Support\InMemoryChannelOptionRepository());
    }

    #[Test]
    public function aRegisteredChannelIsPersistentAndOpsSecure(): void
    {
        $this->channels->save(new Channel(new ChannelName('#Test'), 'alice', new DateTimeImmutable()));
        $options = new \Dbots\Tests\Support\InMemoryChannelOptionRepository();
        $options->set(new ChannelName('#Test'), \Dbots\Domain\Channel\ChannelOption::SecureOps, 'on');
        $provider = new ChannelBlockProvider($this->channels, $options);

        self::assertSame(0x28, $provider->records('C')['#test::options']);
        self::assertSame(0x08, $this->provider->records('C')['#test::options']);
    }

    #[Test]
    public function aSuspendedChannelCarriesItsReason(): void
    {
        $this->channels->save(new Channel(
            new ChannelName('#Test'),
            'alice',
            new DateTimeImmutable(),
            null,
            null,
            null,
            null,
            null,
            null,
            'incumple las normas',
            'Operador',
            new DateTimeImmutable(),
        ));

        $records = $this->provider->records('C');

        self::assertSame('incumple las normas', $records['#test::suspend']);
    }

    #[Test]
    public function anActiveChannelHasNoSuspendRecord(): void
    {
        $this->channels->save(new Channel(new ChannelName('#Test'), 'alice', new DateTimeImmutable()));

        self::assertArrayNotHasKey('#test::suspend', $this->provider->records('C'));
    }
}
