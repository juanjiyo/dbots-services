<?php

declare(strict_types=1);

namespace Dbots\MemoServ\Application\SendMemo;

enum SendMemoResult
{
    case Sent;
    case UnknownRecipient;
    case EmptyBody;
    case NotIdentified;
}
