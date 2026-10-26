<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use Dbots\Sumidero\Application\Port\Out\SumideroStateRepositoryInterface;

use function array_filter;
use function array_splice;
use function array_values;
use function count;
use function in_array;
use function strtolower;

final class InMemorySumideroStateRepository implements SumideroStateRepositoryInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $shuns = [];

    /** @var array<string, array<string, mixed>> */
    private array $tokens = [];

    /** @var array<string, int> */
    private array $offenses = [];

    /** @var list<string> */
    private array $ignored = [];

    /** @var list<string> */
    private array $operators = [];

    /** @var list<array{id: int, pattern: string}> */
    private array $spamPatterns = [];

    public function addShun(string $nick, string $mask, string $host, ?string $ip, int $until, string $reason): void
    {
        $this->shuns[strtolower($nick)] = [
            'nick' => strtolower($nick),
            'mask' => $mask,
            'host' => strtolower($host),
            'ip' => $ip,
            'until' => $until,
            'reason' => $reason,
        ];
    }

    public function findShun(string $nick): ?array
    {
        return $this->shuns[strtolower($nick)] ?? null;
    }

    public function allShuns(): array
    {
        return array_values($this->shuns);
    }

    public function removeShun(string $nick): void
    {
        unset($this->shuns[strtolower($nick)]);
    }

    public function removeShunByMask(string $mask): void
    {
        foreach ($this->shuns as $nick => $shun) {
            if (($shun['mask'] ?? '') === $mask) {
                unset($this->shuns[$nick]);
            }
        }
    }

    public function removeTokensForHost(string $host): void
    {
        $host = strtolower($host);

        foreach ($this->tokens as $token => $data) {
            if (($data['host'] ?? '') === $host) {
                unset($this->tokens[$token]);
            }
        }
    }

    public function addToken(string $token, array $data): void
    {
        $this->tokens[$token] = [
            'token' => $token,
            'nick' => strtolower($data['nick'] ?? ''),
            'host' => strtolower($data['host'] ?? ''),
            'ip' => $data['ip'] ?? null,
            'since' => $data['since'] ?? 0,
            'reason' => $data['reason'] ?? '',
        ];
    }

    public function findToken(string $token): ?array
    {
        return $this->tokens[$token] ?? null;
    }

    public function removeToken(string $token): void
    {
        unset($this->tokens[$token]);
    }

    public function bumpOffense(string $host): int
    {
        $host = strtolower($host);
        $this->offenses[$host] = ($this->offenses[$host] ?? 0) + 1;

        return $this->offenses[$host];
    }

    public function offenseCount(string $host): int
    {
        return $this->offenses[strtolower($host)] ?? 0;
    }

    public function addIgnored(string $pattern): void
    {
        $pattern = strtolower($pattern);

        if (!in_array($pattern, $this->ignored, true)) {
            $this->ignored[] = $pattern;
        }
    }

    public function removeIgnored(string $pattern): void
    {
        $this->ignored = array_values(array_filter(
            $this->ignored,
            static fn (string $item): bool => $item !== strtolower($pattern),
        ));
    }

    public function allIgnored(): array
    {
        return $this->ignored;
    }

    public function addOperator(string $pattern): void
    {
        $pattern = strtolower($pattern);

        if (!in_array($pattern, $this->operators, true)) {
            $this->operators[] = $pattern;
        }
    }

    public function removeOperator(string $pattern): void
    {
        $this->operators = array_values(array_filter(
            $this->operators,
            static fn (string $item): bool => $item !== strtolower($pattern),
        ));
    }

    public function allOperators(): array
    {
        return $this->operators;
    }

    public function addSpamPattern(string $pattern): int
    {
        $id = count($this->spamPatterns) + 1;
        $this->spamPatterns[] = ['id' => $id, 'pattern' => $pattern];

        return $id;
    }

    public function removeSpamPattern(int $position): bool
    {
        if ($position < 1 || $position > count($this->spamPatterns)) {
            return false;
        }

        array_splice($this->spamPatterns, $position - 1, 1);

        return true;
    }

    public function allSpamPatterns(): array
    {
        return $this->spamPatterns;
    }

    public function clearSpamPatterns(): void
    {
        $this->spamPatterns = [];
    }
}
