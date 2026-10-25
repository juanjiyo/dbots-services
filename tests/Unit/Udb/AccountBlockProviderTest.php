<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\Udb;

use DateTimeImmutable;
use Dbots\Domain\Account\Account;
use Dbots\Domain\Account\Nick;
use Dbots\Tests\Support\InMemoryAccountRepository;
use Dbots\Udb\AccountBlockProvider;
use Dbots\Udb\NativeDigest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Locks the two wire conventions that each cost a live failure:
 *
 * 1. record paths never carry the block letter (the module reads path[0] as the
 *    block and starts components at path[3]);
 * 2. the digest is computed over exactly the path shape the module's reference
 *    implementation hashes.
 *
 * Both passed for empty blocks and failed as soon as a block had rows.
 */
final class AccountBlockProviderTest extends TestCase
{
    private InMemoryAccountRepository $accounts;
    private AccountBlockProvider $provider;

    protected function setUp(): void
    {
        $this->accounts = new InMemoryAccountRepository();
        $this->provider = new AccountBlockProvider($this->accounts, 'bots.example.org');
    }

    #[Test]
    public function itPublishesCredentialsWithoutTheBlockLetter(): void
    {
        $this->seed('alice', '$2y$10$xyz');

        self::assertSame(['alice::pass' => 'crypt:$2y$10$xyz'], $this->provider->records('N'));
    }

    #[Test]
    public function itMatchesTheModuleReferenceDigest(): void
    {
        // Computed with the module's native_reference.py for {"alice::pass": "crypt:abc"}.
        $this->seed('alice', 'abc');

        self::assertSame(
            'a478a3be4067046ea85cb6a268f7b2a25c66b2da21d3aa0bd197d83f004eaa24',
            NativeDigest::logicalDigest('N', $this->provider->records('N')),
        );
    }

    #[Test]
    public function itMatchesTheReferenceDigestForTwoAccounts(): void
    {
        $this->seed('alice', 'abc');
        $this->seed('bob', 'def');

        self::assertSame(
            'b1ec16345db3c2718bfb1a8355187a134dc328fb2b4c121401fa17c5a47b4f8b',
            NativeDigest::logicalDigest('N', $this->provider->records('N')),
        );
    }

    #[Test]
    public function itPublishesTheNickServiceMaskInTheSettingsBlock(): void
    {
        $records = $this->provider->records('S');

        self::assertSame(['nickserv' => 'NiCK!NiCK@bots.example.org'], $records);
        self::assertSame(
            '19a4d9b82e3079e7074d8244e99866b6601be0472b0a78d9a8d50e9410c01bf6',
            NativeDigest::logicalDigest('S', $records),
        );
    }

    #[Test]
    public function itLeavesOtherBlocksEmpty(): void
    {
        foreach (['C', 'I', 'L', 'K'] as $block) {
            self::assertSame([], $this->provider->records($block), $block);
        }
    }

    private function seed(string $nick, string $hash): void
    {
        $this->accounts->save(new Account(new Nick($nick), $hash, null, new DateTimeImmutable()));
    }
}
