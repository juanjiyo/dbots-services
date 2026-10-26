<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\ValidateRegistration;

enum ValidateRegistrationResult
{
    case Validated;
    case InvalidNick;
    case NoPendingRegistration;
    case Expired;
    case WrongCode;
}
