<?php

declare(strict_types=1);

namespace Dbots\CRegServ\Application\Request;

enum RequestRegistrationResult
{
    case Requested;
    case NotIdentified;
    case InvalidChannel;
    case AlreadyRegistered;
    case DuplicateRequest;
    case PreviouslyDenied;
    case NotChannelOperator;
    case WeakPassword;
}
