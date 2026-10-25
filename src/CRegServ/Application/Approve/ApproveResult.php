<?php

declare(strict_types=1);

namespace Dbots\CRegServ\Application\Approve;

enum ApproveResult
{
    case Approved;
    case NoPendingRequest;
    case AlreadyRegistered;
    case InvalidChannel;
}
