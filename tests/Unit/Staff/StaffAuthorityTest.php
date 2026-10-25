<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\Staff;

use Dbots\Domain\Staff\StaffRank;
use Dbots\Staff\Application\StaffAuthority;
use Dbots\Tests\Support\InMemoryStaffRankRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StaffAuthorityTest extends TestCase
{
    private InMemoryStaffRankRepository $ranks;
    private StaffAuthority $authority;

    protected function setUp(): void
    {
        $this->ranks = new InMemoryStaffRankRepository();
        $this->authority = new StaffAuthority($this->ranks);
    }

    #[Test]
    public function itScoresTheLadderOnTheOriginalScale(): void
    {
        self::assertSame(4, StaffRank::PreOperador->level());
        self::assertSame(5, StaffRank::Operador->level());
        self::assertSame(7, StaffRank::Administrador->level());
        self::assertSame(8, StaffRank::Root->level());
    }

    #[Test]
    public function anAccountWithoutRankHasNoAuthority(): void
    {
        self::assertSame(0, $this->authority->levelOf(null));
        self::assertSame(0, $this->authority->levelOf('desconocido'));
        self::assertFalse($this->authority->isOperator('desconocido'));
    }

    #[Test]
    public function operatorIsTheThreshold(): void
    {
        $this->ranks->assign('carol', StaffRank::PreOperador);
        self::assertFalse($this->authority->isOperator('carol'));

        $this->ranks->assign('carol', StaffRank::Operador);
        self::assertTrue($this->authority->isOperator('carol'));
        self::assertTrue($this->authority->atLeast('carol', StaffRank::Operador));
        self::assertFalse($this->authority->atLeast('carol', StaffRank::Administrador));
    }
}
