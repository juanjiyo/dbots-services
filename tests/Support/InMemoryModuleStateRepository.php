<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use Dbots\Module\ModuleStateRepositoryInterface;

final class InMemoryModuleStateRepository implements ModuleStateRepositoryInterface
{
    /** @var array<string, bool> */
    private array $state = [];

    public function isEnabled(string $name, bool $default): bool
    {
        if (!isset($this->state[$name])) {
            $this->state[$name] = $default;
        }

        return $this->state[$name];
    }

    public function setEnabled(string $name, bool $enabled): void
    {
        $this->state[$name] = $enabled;
    }

    public function all(): array
    {
        return $this->state;
    }
}
