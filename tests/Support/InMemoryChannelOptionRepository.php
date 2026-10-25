<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use Dbots\ChanServ\Application\Port\Out\ChannelOptionRepositoryInterface;
use Dbots\Domain\Channel\ChannelName;
use Dbots\Domain\Channel\ChannelOption;

final class InMemoryChannelOptionRepository implements ChannelOptionRepositoryInterface
{
    /** @var array<string, array<string, string>> */
    private array $options = [];

    public function isEnabled(ChannelName $channel, ChannelOption $option): bool
    {
        return 'on' === ($this->options[$channel->value][$option->value] ?? '');
    }

    public function optionsFor(ChannelName $channel): array
    {
        return $this->options[$channel->value] ?? [];
    }

    public function set(ChannelName $channel, ChannelOption $option, string $value): void
    {
        $this->options[$channel->value][$option->value] = $value;
    }

    public function forget(ChannelName $channel): void
    {
        unset($this->options[$channel->value]);
    }
}
