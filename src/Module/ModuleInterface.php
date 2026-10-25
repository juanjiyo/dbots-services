<?php

declare(strict_types=1);

namespace Dbots\Module;

use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\NetworkState;

interface ModuleInterface
{
    /** The nick the module speaks as; it is introduced as a pseudo-client. */
    public function nick(): string;

    public function description(): string;

    /**
     * Every parsed message reaches every module. A module that only cares about
     * its own nick decides that here, with `$message->target()`.
     */
    public function handle(IrcMessage $message, NetworkState $state, ModuleContext $context): void;
}
