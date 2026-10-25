<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use Dbots\ClonesServ\Application\Port\Out\ClonesWriterInterface;
use Dbots\ClonesServ\Domain\ClonesException;
use RuntimeException;

final class RecordingClonesWriter implements ClonesWriterInterface
{
    /** @var list<list<ClonesException>> */
    public array $writes = [];

    public bool $rehashOk = true;

    public bool $throwOnWrite = false;

    /**
     * @param list<ClonesException> $exceptions
     */
    public function write(array $exceptions): bool
    {
        $this->writes[] = $exceptions;

        if ($this->throwOnWrite) {
            throw new RuntimeException('write failed');
        }

        return $this->rehashOk;
    }
}
