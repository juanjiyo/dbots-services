<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use Dbots\NickServ\Application\Port\Out\AccountProjectionInterface;

final class RecordingProjection implements AccountProjectionInterface
{
    /** @var list<string> */
    public array $published = [];

    /** @var list<string> */
    public array $removed = [];

    public function publish(string $nick): void
    {
        $this->published[] = $nick;
    }

    public function remove(string $nick): void
    {
        $this->removed[] = $nick;
    }
}
