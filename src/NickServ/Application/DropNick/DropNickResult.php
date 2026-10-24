<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\DropNick;

enum DropNickResult
{
    case Dropped;
    case WrongPassword;
    case NotRegistered;
    case NotIdentified;
    case OwnsChannels;
}
