<?php

declare(strict_types=1);

namespace Modules\Ping;

use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\NetworkState;
use Dbots\Module\ModuleContext;
use Dbots\Module\ModuleInterface;

use function sprintf;
use function strtoupper;

/** The smallest useful module: it answers PING and shows the path works. */
final class PingModule implements ModuleInterface
{
    public function nick(): string
    {
        return 'PiNG';
    }

    public function description(): string
    {
        return 'Responde PING con PONG.';
    }

    public function handle(IrcMessage $message, NetworkState $state, ModuleContext $context): void
    {
        if ('PRIVMSG' !== $message->command) {
            return;
        }

        if (!$context->isAddressedTo($message, $this->nick())) {
            return;
        }

        $sender = $message->source();

        if (null === $sender) {
            return;
        }

        $context->reply($sender, sprintf('PONG %s', strtoupper($message->text())));
    }
}
