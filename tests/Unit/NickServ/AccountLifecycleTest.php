<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\NickServ;

use Dbots\Domain\Account\Account;
use Dbots\Domain\Account\Nick;
use Dbots\Domain\Account\Password;
use Dbots\NickServ\Application\Identify\IdentifyHandler;
use Dbots\NickServ\Application\Identify\IdentifyResult;
use Dbots\NickServ\Application\RegisterNick\RegisterNickHandler;
use Dbots\NickServ\Application\RegisterNick\RegisterNickResult;
use Dbots\Tests\Support\InMemoryAccountRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AccountLifecycleTest extends TestCase
{
    private InMemoryAccountRepository $accounts;
    private RegisterNickHandler $register;
    private IdentifyHandler $identify;

    protected function setUp(): void
    {
        $this->accounts = new InMemoryAccountRepository();
        $this->register = new RegisterNickHandler($this->accounts);
        $this->identify = new IdentifyHandler($this->accounts);
    }

    #[Test]
    public function itRegistersANickAndStoresOnlyAHash(): void
    {
        self::assertSame(RegisterNickResult::Registered, ($this->register)('JuanJo', 'hunter2', 'j@example.org'));

        $account = $this->accounts->findByNick(new Nick('juanjo'));

        self::assertNotNull($account);
        self::assertNotSame('hunter2', $account->passwordHash);
        self::assertSame('j@example.org', $account->email);
        self::assertTrue($account->authenticatesWith(Password::fromPlain('hunter2')));
    }

    #[Test]
    public function itRejectsADuplicateNickCaseInsensitively(): void
    {
        ($this->register)('JuanJo', 'hunter2', null);

        self::assertSame(RegisterNickResult::AlreadyRegistered, ($this->register)('JUANJO', 'otherpass', null));
    }

    #[Test]
    public function itRejectsAnInvalidNickname(): void
    {
        self::assertSame(RegisterNickResult::InvalidNick, ($this->register)('bad nick', 'hunter2', null));
    }

    #[Test]
    public function itRejectsAShortPassword(): void
    {
        self::assertSame(RegisterNickResult::WeakPassword, ($this->register)('JuanJo', '123', null));
    }

    #[Test]
    public function itIdentifiesWithTheRightPassword(): void
    {
        $this->seedAccount('JuanJo', 'hunter2');

        self::assertSame(IdentifyResult::Identified, ($this->identify)('juanjo', 'hunter2'));
    }

    #[Test]
    public function itRejectsAWrongPassword(): void
    {
        $this->seedAccount('JuanJo', 'hunter2');

        self::assertSame(IdentifyResult::WrongPassword, ($this->identify)('JuanJo', 'wrongpass'));
    }

    #[Test]
    public function itReportsAnUnknownAccount(): void
    {
        self::assertSame(IdentifyResult::UnknownAccount, ($this->identify)('Nobody', 'hunter2'));
    }

    private function seedAccount(string $nick, string $password): void
    {
        $this->accounts->save(Account::register(
            new Nick($nick),
            Password::fromPlain($password),
            null,
            new DateTimeImmutable(),
        ));
    }
}
