<?php

declare(strict_types=1);

namespace Dbots\Domain\Channel;

/**
 * The channel settings `ch.mrc` stores under `configuracion`. Only the ones the
 * services themselves read live here; UDB 4 has no record for any of them.
 */
enum ChannelSetting: string
{
    case Description = 'description';
    case Url = 'url';
    case Email = 'email';
    case EntryMessage = 'entry_message';
    case Password = 'password_hash';

    /** The column this setting is stored in; never taken from user input. */
    public function column(): string
    {
        return $this->value;
    }
}
