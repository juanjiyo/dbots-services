<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\Vreg;

use Dbots\Udb\Application\Port\Out\UdbSettingsRepositoryInterface;

final readonly class VregHandler
{
    private const KEY = 'V';

    public function __construct(private UdbSettingsRepositoryInterface $settings)
    {
    }

    public function enable(): void
    {
        $this->settings->set(self::KEY, 'on');
    }

    public function disable(): void
    {
        $this->settings->set(self::KEY, 'off');
    }

    public function isEnabled(): bool
    {
        return 'on' === $this->settings->get(self::KEY);
    }
}
