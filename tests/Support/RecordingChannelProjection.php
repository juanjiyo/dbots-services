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
    public array $accessAdded = [];

    /** @var list<string> */
    public array $accessRemoved = [];

    /** @var list<string> */
    public array $topics = [];

    /** @var list<string> */
    public array $suspensions = [];

    /** @var list<string> */
    public array $forbids = [];

    /** @var list<string> */
    public array $founderRemoved = [];

    public function publishRegistered(string $channel): void
    {
        $this->registered[] = $channel;
    }

    public function publishDropped(string $channel): void
    {
        $this->dropped[] = $channel;
    }

    public function publishAccess(string $channel, string $nick): void
    {
        $this->accessAdded[] = $channel . ':' . $nick;
    }

    public function removeAccess(string $channel, string $nick): void
    {
        $this->accessRemoved[] = $channel . ':' . $nick;
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

    public function publishFounderRemoved(string $channel): void
    {
        $this->founderRemoved[] = $channel;
    }
}
