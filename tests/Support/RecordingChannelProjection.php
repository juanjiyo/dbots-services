<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use Dbots\ChanServ\Application\Port\Out\ChannelProjectionInterface;

final class RecordingChannelProjection implements ChannelProjectionInterface
{
    /** @var list<string> */
    public array $registered = [];

    /** @var list<string> */
    public array $dropped = [];

    /** @var list<string> */
    public array $topics = [];

    /** @var list<string> */
    public array $suspensions = [];

    /** @var list<string> */
    public array $forbids = [];

    public function publishRegistered(string $channel): void
    {
        $this->registered[] = $channel;
    }

    public function publishDropped(string $channel): void
    {
        $this->dropped[] = $channel;
    }

    public function publishTopic(string $channel): void
    {
        $this->topics[] = $channel;
    }

    public function publishSuspension(string $channel): void
    {
        $this->suspensions[] = $channel;
    }

    public function publishForbid(string $channel, ?string $reason): void
    {
        $this->forbids[] = null === $reason ? $channel.' off' : $channel.' on';
    }

}
