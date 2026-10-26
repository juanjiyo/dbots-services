<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\NickServ;

use Dbots\Domain\Account\Account;
use Dbots\Domain\Account\Nick;
use Dbots\Irc\Application\AccountResolver;
use Dbots\Irc\Application\NetworkState;
use Dbots\NickServ\Application\ApplyAutomode\ApplyAutomode;
use Dbots\Tests\Support\InMemoryAccountRepository;
use Dbots\Tests\Support\RecordingTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApplyAutomodeTest extends TestCase
{
    private InMemoryAccountRepository $accounts;
    private RecordingTransport $transport;
    private ApplyAutomode $applier;
    private NetworkState $state;

    protected function setUp(): void
    {
        $this->accounts = new InMemoryAccountRepository();
        $this->transport = new RecordingTransport();
        $this->applier = new ApplyAutomode(
            new AccountResolver($this->accounts),
            $this->transport,
            '003A00001',
        );
        $this->state = new NetworkState();
    }

    #[Test]
    public function itSendsSvs2modeWhenUserIdentifiesAndHasAutomode(): void
    {
        $this->accounts->save(new Account(
            new Nick('JuanJo'),
            '$2y$10$test',
            null,
            new \DateTimeImmutable(),
            automode: 'x',
        ));
        $this->state->introduceUser('001AAAAAA', 'JuanJo');
        $this->state->applyModeChange('001AAAAAA', '+r');

        ($this->applier)('001AAAAAA', $this->state);

        self::assertSame([':003A00001 SVS2MODE 001AAAAAA +x'], $this->transport->written);
    }

    #[Test]
    public function itDoesNothingWhenUserHasNoAutomode(): void
    {
        $this->accounts->save(new Account(
            new Nick('JuanJo'),
            '$2y$10$test',
            null,
            new \DateTimeImmutable(),
        ));
        $this->state->introduceUser('001AAAAAA', 'JuanJo');
        $this->state->applyModeChange('001AAAAAA', '+r');

        ($this->applier)('001AAAAAA', $this->state);

        self::assertSame([], $this->transport->written);
    }

    #[Test]
    public function itDoesNothingWhenUserIsNotIdentified(): void
    {
        $this->accounts->save(new Account(
            new Nick('JuanJo'),
            '$2y$10$test',
            null,
            new \DateTimeImmutable(),
            automode: 'x',
        ));
        $this->state->introduceUser('001AAAAAA', 'JuanJo');

        ($this->applier)('001AAAAAA', $this->state);

        self::assertSame([], $this->transport->written);
    }
}
