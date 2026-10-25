<?php

declare(strict_types=1);

namespace Dbots\Module;

/**
 * Optional hooks for modules that need to react to being loaded or unloaded.
 *
 * A module does not have to implement this interface. When it does, the daemon
 * calls onLoad() after the pseudo-client has been introduced and onUnload()
 * before it is removed.
 */
interface ModuleLifecycleInterface
{
    public function onLoad(ModuleContext $context): void;

    public function onUnload(ModuleContext $context): void;
}
