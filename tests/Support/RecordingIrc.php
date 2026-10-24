<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use Dbots\NickServ\Application\Port\Out\ReplyInterface;

final class RecordingIrc implements ReplyInterface
{
    /** @var list<string> */
    public array $replies = [];

    public function reply(string $targetUid, string $text): void
    {
        $this->replies[] = $targetUid . ': ' . $text;
    }
}
