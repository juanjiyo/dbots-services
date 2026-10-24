<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\Identify;

enum IdentifyResult
{
    case Identified;
    case UnknownAccount;
    case WrongPassword;
    case InvalidNick;
}
