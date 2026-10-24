<?php

declare(strict_types=1);

namespace Dbots\Domain\Link;

enum LinkState: string
{
    case Disconnected = 'disconnected';
    case Handshaking = 'handshaking';
    case Synced = 'synced';
    case Failed = 'failed';
}
