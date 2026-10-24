<?php

declare(strict_types=1);

namespace Dbots\ChanServ\Application\DropChannel;

enum DropChannelResult
{
    case Dropped;
    case NotRegistered;
    case NotFounder;
    case InvalidChannel;
}
