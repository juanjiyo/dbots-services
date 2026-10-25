<?php

declare(strict_types=1);

namespace Dbots\Domain\Registration;

enum ChannelKind: string
{
    case Normal = 'NORMAL';
    case Official = 'OFICIAL';
    case Commercial = 'COMERCIAL';
}
