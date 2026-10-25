<?php

declare(strict_types=1);

namespace Dbots\CRegServ\Application\Deny;

enum DenyResult
{
    case Denied;
    case NoPendingRequest;
    case InvalidChannel;
}
