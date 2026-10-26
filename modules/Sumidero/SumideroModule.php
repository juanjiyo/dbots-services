<?php

declare(strict_types=1);

namespace Modules\Sumidero;

use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\NetworkState;
use Dbots\Module\ModuleContext;
use Dbots\Module\ModuleInterface;
use Dbots\Module\ModuleLifecycleInterface;
use Dbots\Sumidero\Adapter\Out\Persistence\SqliteSumideroStateRepository;
use Dbots\Sumidero\Application\Config;
use Dbots\Sumidero\Application\SumideroService;

use function sprintf;
use function strcasecmp;
use function str_starts_with;
use function time;

/**
 * Anti-spam/anti-clone sink module.
 *
 * Forces unidentified users and flooders into the configured sink channel and
 * shuns them until they complete the web verification or a staff member releases
 * them.
 */
final class SumideroModule implements ModuleInterface, ModuleLifecycleInterface
{
    private ?Config $config = null;

    private ?SumideroService $service = null;

    public function nick(): string
    {
        return 'Sumidero';
    }

    public function description(): string
    {
        return 'Servicio anti-spam y anti-clon.';
    }

    public function onLoad(ModuleContext $context): void
    {
        $this->config = new Config();
        $repository = new SqliteSumideroStateRepository($context->pdo);

        $this->service = new SumideroService(
            $this->config,
            $repository,
            $context->enforcement,
            $context->uid,
            static fn (string $target, string $text): null => $context->reply($target, $text),
            static fn (string $line): null => $context->line($line),
            static fn (): int => time(),
        );

        $this->service->joinChannels();
    }

    public function onUnload(ModuleContext $context): void
    {
        $this->service = null;
        $this->config = null;
    }

    public function handle(IrcMessage $message, NetworkState $state, ModuleContext $context): void
    {
        if (null === $this->service || null === $this->config) {
            return;
        }

        $source = $message->source();

        if (null === $source) {
            return;
        }

        match ($message->command) {
            'UID' => $this->onUserIntroduced($source, $state, $context),
            'QUIT', 'KICK' => $this->service->forgetFlood($source),
            'PRIVMSG' => $this->onPrivmsg($message, $source, $state, $context),
            default => null,
        };
    }

    private function onUserIntroduced(string $uid, NetworkState $state, ModuleContext $context): void
    {
        if (null === $this->service || null === $this->config) {
            return;
        }

        $this->service->onUserIntroduced($uid, $state);

        if (!$this->config->gateEnabled || $state->hasMode($uid, 'r')) {
            return;
        }

        $context->schedule($this->config->validateSeconds, function () use ($uid, $state): void {
            $this->service?->checkGate($uid, $state);
        });
    }

    private function onPrivmsg(IrcMessage $message, string $source, NetworkState $state, ModuleContext $context): void
    {
        if (null === $this->service) {
            return;
        }

        $target = $message->target();

        if (null === $target) {
            return;
        }

        $text = $message->text();

        if (0 === strcasecmp($target, $this->nick()) || $target === $context->uid) {
            $this->service->onPrivateMessage($source, $text, $state);

            return;
        }

        if (!str_starts_with($target, '#')) {
            return;
        }

        $this->service->onChannelMessage($target, $source, $text, $state);
    }
}
