<?php

declare(strict_types=1);

namespace Dbots\Sumidero\Application\Port\Out;

interface SumideroStateRepositoryInterface
{
    /**
     * Stores an active shun. The key is the lowercased nick.
     */
    public function addShun(string $nick, string $mask, string $host, ?string $ip, int $until, string $reason): void;

    /**
     * @return array<string, mixed>|null
     */
    public function findShun(string $nick): ?array;

    /**
     * @return list<array<string, mixed>>
     */
    public function allShuns(): array;

    public function removeShun(string $nick): void;

    public function removeShunByMask(string $mask): void;

    public function removeTokensForHost(string $host): void;

    /**
     * Stores a verification token.
     *
     * @param array<string, mixed> $data
     */
    public function addToken(string $token, array $data): void;

    /**
     * @return array<string, mixed>|null
     */
    public function findToken(string $token): ?array;

    public function removeToken(string $token): void;

    /**
     * Records one offense for a host.
     */
    public function bumpOffense(string $host): int;

    public function offenseCount(string $host): int;

    public function addIgnored(string $pattern): void;

    public function removeIgnored(string $pattern): void;

    /**
     * @return list<string>
     */
    public function allIgnored(): array;

    public function addOperator(string $pattern): void;

    public function removeOperator(string $pattern): void;

    /**
     * @return list<string>
     */
    public function allOperators(): array;

    public function addSpamPattern(string $pattern): int;

    public function removeSpamPattern(int $position): bool;

    /**
     * @return list<array{id: int, pattern: string}>
     */
    public function allSpamPatterns(): array;

    public function clearSpamPatterns(): void;
}
