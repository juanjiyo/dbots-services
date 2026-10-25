<?php

declare(strict_types=1);

namespace Dbots\Udb;

use Dbots\Udb\Application\Port\Out\UdbSettingsRepositoryInterface;

final readonly class UdbSettingsProjection
{
    public function __construct(
        private UdbSettingsRepositoryInterface $settings,
        private UdbAuthority $authority,
    ) {
    }

    public function publish(string $key): void
    {
        $value = $this->settings->get($key);

        if (null === $value) {
            $this->authority->delete('S', $key);

            return;
        }

        $this->authority->insert('S', $key, $value);
    }
}
