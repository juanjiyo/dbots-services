<?php

declare(strict_types=1);

namespace Dbots\Module;

interface ModuleStateRepositoryInterface
{
    public function isEnabled(string $name, bool $default): bool;

    public function setEnabled(string $name, bool $enabled): void;

    /** @return array<string, bool> module name => enabled */
    public function all(): array;
}
