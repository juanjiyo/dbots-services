<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\RegisterNick;

enum RegisterNickResult
{
    case Registered;
    case AlreadyRegistered;
    case InvalidNick;
    case WeakPassword;
}
