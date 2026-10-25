<?php

declare(strict_types=1);

namespace Dbots\ClonesServ\Application\Port\Out;

use Dbots\ClonesServ\Domain\ClonesException;

interface ClonesWriterInterface
{
    /**
     * Rewrites the IRCd clone exceptions config and signals the IRCd.
     *
     * @param list<ClonesException> $exceptions
     *
     * @return bool true when the IRCd was signaled, false when no PID file exists
     */
    public function write(array $exceptions): bool;
}
