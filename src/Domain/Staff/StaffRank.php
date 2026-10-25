<?php

declare(strict_types=1);

namespace Dbots\Domain\Staff;

use function strtoupper;

/**
 * The network staff ladder the dBoTs kept per representative, which `$s.n()`
 * read back. Co-Administrador was removed by decision, so the ladder is
 * Pre-Operador, Operador, Administrador and Root.
 *
 * The numbers keep the original scale: they are NOT channel levels (0..500),
 * they are the staff scale `ch.mrc` compared with `$s.n(...) >= 5`.
 */
enum StaffRank: string
{
    case PreOperador = 'PREOPER';
    case Operador = 'OPER';
    case Administrador = 'ADMIN';
    case Root = 'ROOT';

    public function level(): int
    {
        return match ($this) {
            self::PreOperador => 4,
            self::Operador => 5,
            self::Administrador => 7,
            self::Root => 8,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PreOperador => 'Pre-Operador',
            self::Operador => 'Operador',
            self::Administrador => 'Administrador',
            self::Root => 'Root',
        };
    }

    /** The lowest rank that counts as an operator, as `$s.n(...) >= 5` did. */
    public static function operatorLevel(): int
    {
        return self::Operador->level();
    }

    public static function fromName(string $name): ?self
    {
        return self::tryFrom(strtoupper($name));
    }
}
