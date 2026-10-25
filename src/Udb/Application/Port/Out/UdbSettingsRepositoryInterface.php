<?php

declare(strict_types=1);

namespace Dbots\Udb\Application\Port\Out;

interface UdbSettingsRepositoryInterface
{
    public function get(string $key): ?string;

    public function set(string $key, string $value): void;

    /**
     * @return array<string, string>
     */
    public function all(): array;
}
