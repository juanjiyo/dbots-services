<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\ChangePassword;

enum ChangePasswordResult
{
    case Changed;
    case WrongPassword;
    case NotRegistered;
    case WeakPassword;
    case NotIdentified;
}
