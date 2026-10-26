<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\NickServ;

use Dbots\Domain\Account\Account;
use Dbots\Domain\Account\Nick;
use Dbots\Irc\Application\NetworkState;
use Dbots\NickServ\Application\UpdateAutomode\UpdateAutomodeHandler;
use Dbots\Tests\Support\InMemoryAccountRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UpdateAutomodeHandlerTest extends TestCase
{
    private InMemoryAccountRepository $accounts;
    private UpdateAutomodeHandler $handler;
    private NetworkState $state;

    protected function setUp(): void
    {
        $this->accounts = new InMemoryAccountRepository();
        $this->handler = new UpdateAutomodeHandler($this->accounts);
        $this->state = new NetworkState();
        $this->state->introduceUser('001AAAAAA', 'JuanJo');
        $this->state->applyModeChange('001AAAAAA', '+r');
    }

    #[Test]
    public function itStoresValidModes(): void
    {
        $this->accounts->save($this->account('JuanJo'));

        $result = ($this->handler)('001AAAAAA', 'JuanJo', '+x', $this->state);

        self::assertSame('changed', $result->status);
        self::assertSame('x', $this->accounts->findByNick(new Nick('JuanJo'))?->automode);
    }

    #[Test]
    public function itClearsStoredModesWhenModesAreEmpty(): void
    {
        $this->accounts->save($this->account('JuanJo', 'x'));

        $result = ($this->handler)('001AAAAAA', 'JuanJo', null, $this->state);

        self::assertSame('cleared', $result->status);
        self::assertNull($this->accounts->findByNick(new Nick('JuanJo'))?->automode);
    }

    #[Test]
    public function itRejectsOperOnlyModes(): void
    {
        $this->accounts->save($this->account('JuanJo'));

        $result = ($this->handler)('001AAAAAA', 'JuanJo', '+o', $this->state);

        self::assertSame('invalid_value', $result->status);
        self::assertNull($this->accounts->findByNick(new Nick('JuanJo'))?->automode);
    }

    #[Test]
    public function itRejectsSslMode(): void
    {
        $this->accounts->save($this->account('JuanJo'));

        $result = ($this->handler)('001AAAAAA', 'JuanJo', '+z', $this->state);

        self::assertSame('invalid_value', $result->status);
        self::assertNull($this->accounts->findByNick(new Nick('JuanJo'))?->automode);
    }

    #[Test]
    public function itRequiresIdentification(): void
    {
        $this->accounts->save($this->account('JuanJo'));
        $this->state = new NetworkState();
        $this->state->introduceUser('001AAAAAA', 'JuanJo');

        $result = ($this->handler)('001AAAAAA', 'JuanJo', '+x', $this->state);

        self::assertSame('not_identified', $result->status);
    }

    #[Test]
    public function itRejectsChangesToAnotherUserForNonOpers(): void
    {
        $this->accounts->save($this->account('JuanJo'));
        $this->accounts->save($this->account('Other'));
        $this->state = new NetworkState();
        $this->state->introduceUser('001BBBBBB', 'Other');
        $this->state->applyModeChange('001BBBBBB', '+r');

        $result = ($this->handler)('001BBBBBB', 'JuanJo', '+x', $this->state);

        self::assertSame('not_authorized', $result->status);
    }

    #[Test]
    public function opersCanChangeAnotherUsersAutomode(): void
    {
        $this->accounts->save($this->account('JuanJo'));
        $this->state = new NetworkState();
        $this->state->introduceUser('001BBBBBB', 'Other');
        $this->state->applyModeChange('001BBBBBB', '+ro');

        $result = ($this->handler)('001BBBBBB', 'JuanJo', '+x', $this->state);

        self::assertSame('changed', $result->status);
        self::assertSame('x', $this->accounts->findByNick(new Nick('JuanJo'))?->automode);
    }

    private function account(string $nick, ?string $automode = null): Account
    {
        return new Account(
            new Nick($nick),
            '$2y$10$test',
            null,
            new \DateTimeImmutable(),
            automode: $automode,
        );
    }
}
