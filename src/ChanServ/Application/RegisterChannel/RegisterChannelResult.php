<?php

declare(strict_types=1);

namespace Dbots\ChanServ\Application\RegisterChannel;

enum RegisterChannelResult
{
    case Registered;
    case AlreadyRegistered;
    case InvalidChannel;
    case NotIdentified;
}
