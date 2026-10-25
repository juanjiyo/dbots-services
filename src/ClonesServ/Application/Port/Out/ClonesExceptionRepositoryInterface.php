<?php

declare(strict_types=1);

namespace Dbots\ClonesServ\Application\Port\Out;

use DateTimeImmutable;
use Dbots\ClonesServ\Domain\ClonesException;

interface ClonesExceptionRepositoryInterface
{
    public function save(ClonesException $exception): ClonesException;

    public function findByHost(string $host): ?ClonesException;

    public function deleteByHost(string $host): bool;

    /**
     * @return list<ClonesException>
     */
    public function all(): array;

    /**
     * @return list<ClonesException>
     */
    public function allActive(DateTimeImmutable $now): array;

    public function linkNick(string $nick, string $host): void;

    public function hostForNick(string $nick): ?string;
}
