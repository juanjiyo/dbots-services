<?php

declare(strict_types=1);

namespace Dbots\Udb;

use Dbots\Udb\Application\Port\Out\UdbSettingsRepositoryInterface;

/**
 * Projects configurable UDB `S` block service settings.
 */
final readonly class UdbSettingsBlockProvider implements BlockProviderInterface
{
    public function __construct(private UdbSettingsRepositoryInterface $settings)
    {
    }

    public function records(string $block): array
    {
        if ('S' !== $block) {
            return [];
        }

        return $this->settings->all();
    }
}
