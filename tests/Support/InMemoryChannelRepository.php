<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use DateTimeImmutable;
use Dbots\ChanServ\Application\Port\Out\ChannelRepositoryInterface;
use Dbots\Domain\Channel\Channel;
use Dbots\Domain\Channel\ChannelName;
use Dbots\Domain\Channel\ChannelSetting;

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

    public function updateFounder(Channel $channel): void
    {
        $this->channels[$channel->name->value] = $channel;
    }

    public function updateTopic(Channel $channel): void
    {
        $this->channels[$channel->name->value] = $channel;
    }

    public function updateSuccessor(Channel $channel): void
    {
        $this->channels[$channel->name->value] = $channel;
    }

    public function updateSetting(ChannelName $name, ChannelSetting $setting, ?string $value): void
    {
        $channel = $this->channels[$name->value] ?? null;

        if (null === $channel) {
            return;
        }

        $this->channels[$name->value] = match ($setting) {
            ChannelSetting::Description => new Channel($channel->name, $channel->founder, $channel->createdAt, $channel->topic, $channel->passwordHash, $value, $channel->url, $channel->email, $channel->entryMessage, $channel->suspendReason, $channel->suspendedBy, $channel->suspendedAt, $channel->successor),
            ChannelSetting::Url => new Channel($channel->name, $channel->founder, $channel->createdAt, $channel->topic, $channel->passwordHash, $channel->description, $value, $channel->email, $channel->entryMessage, $channel->suspendReason, $channel->suspendedBy, $channel->suspendedAt, $channel->successor),
            ChannelSetting::Email => new Channel($channel->name, $channel->founder, $channel->createdAt, $channel->topic, $channel->passwordHash, $channel->description, $channel->url, $value, $channel->entryMessage, $channel->suspendReason, $channel->suspendedBy, $channel->suspendedAt, $channel->successor),
            ChannelSetting::EntryMessage => new Channel($channel->name, $channel->founder, $channel->createdAt, $channel->topic, $channel->passwordHash, $channel->description, $channel->url, $channel->email, $value, $channel->suspendReason, $channel->suspendedBy, $channel->suspendedAt, $channel->successor),
            ChannelSetting::Password => new Channel($channel->name, $channel->founder, $channel->createdAt, $channel->topic, $value, $channel->description, $channel->url, $channel->email, $channel->entryMessage, $channel->suspendReason, $channel->suspendedBy, $channel->suspendedAt, $channel->successor),
        };
    }

    public function updateSuspension(Channel $channel): void
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

    public function seed(string $name, string $founder, ?string $passwordHash = null): void
    {
        $this->channels[strtolower($name)] = new Channel(new ChannelName($name), $founder, new DateTimeImmutable(), null, $passwordHash);
    }
}
