<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use DateTimeImmutable;
use Dbots\ChanServ\Application\Port\Out\ChannelRepositoryInterface;
use Dbots\Domain\Channel\Channel;
use Dbots\Domain\Channel\ChannelName;

final class InMemoryChannelRepository implements ChannelRepositoryInterface
{
    /** @var array<string, Channel> */
    private array $channels = [];

    public function find(ChannelName $name): ?Channel
    {
        return $this->channels[$name->value] ?? null;
    }

    public function save(Channel $channel): void
    {
        $this->channels[$channel->name->value] = $channel;
    }

    public function updateTopic(Channel $channel): void
    {
        $this->channels[$channel->name->value] = $channel;
    }

    public function delete(ChannelName $name): void
    {
        unset($this->channels[$name->value]);
    }

    public function all(): array
    {
        return array_values($this->channels);
    }

    public function seed(string $name, string $founder): void
    {
        $this->channels[strtolower($name)] = new Channel(new ChannelName($name), $founder, new DateTimeImmutable());
    }
}
