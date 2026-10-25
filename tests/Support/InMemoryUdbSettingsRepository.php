<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use Dbots\Udb\Application\Port\Out\UdbSettingsRepositoryInterface;

use function strtolower;

final class InMemoryUdbSettingsRepository implements UdbSettingsRepositoryInterface
{
    /** @var array<string, string> */
    private array $settings = [];

    public function get(string $key): ?string
    {
        return $this->settings[strtolower($key)] ?? null;
    }

    public function set(string $key, string $value): void
    {
        $this->settings[strtolower($key)] = $value;
    }

    public function all(): array
    {
        return $this->settings;
    }
}
