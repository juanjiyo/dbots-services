<?php

declare(strict_types=1);

namespace Dbots\Domain\Registration;

/** A request waits for staff; supports were dropped from the flow. */
enum RequestStatus: string
{
    case Pending = 'PENDIENTE';
    case Approved = 'ACEPTADO';
    case Denied = 'DENEGADO';
}
