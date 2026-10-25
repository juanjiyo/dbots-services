<?php

declare(strict_types=1);

namespace Dbots\HeLPServ\Adapter\In\Irc;

use Dbots\HeLPServ\Application\Help\NetworkHelp;
use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\Help\IrcFormat;
use Dbots\Irc\Application\NetworkState;
use Dbots\NickServ\Application\Port\Out\ReplyInterface;

use function array_filter;
use function array_shift;
use function array_values;
use function explode;
use function sprintf;
use function strcasecmp;
use function strtoupper;

/**
 * Global help, the way the original HeLP bot answers: one command per topic and
 * a single "comando desconocido" reply for anything else.
 */
final readonly class HeLPServCommandHandler
{
    /** @param array<string, string> $bots nick => description */
    public function __construct(
        private ReplyInterface $reply,
        private string $serviceNick,
        private string $serviceUid,
        private string $nickServNick,
        private string $cregNick,
        private string $chanNick,
        private array $bots,
    ) {
    }

    public function handle(IrcMessage $message, NetworkState $state): void
    {
        if ('PRIVMSG' !== $message->command) {
            return;
        }

        $target = $message->target();

        if (null === $target || !$this->isAddressedToMe($target)) {
            return;
        }

        $sender = $message->source();

        if (null === $sender) {
            return;
        }

        $parts = $this->tokenise($message->text());

        if ([] === $parts) {
            $this->say($sender, NetworkHelp::index($this->serviceNick));

            return;
        }

        $command = strtoupper((string) array_shift($parts));

        // The original spells AYUDA <tema> as the same thing as <tema>.
        if (('AYUDA' === $command || 'HELP' === $command) && [] !== $parts) {
            $command = strtoupper((string) array_shift($parts));
        }

        $this->say($sender, $this->text($command));
    }

    /** @return list<string> */
    private function text(string $command): array
    {
        return match ($command) {
            'AYUDA', 'HELP' => NetworkHelp::index($this->serviceNick),
            'UMODES' => NetworkHelp::umodes(),
            'CMODES' => NetworkHelp::cmodes(),
            'BOTS' => NetworkHelp::bots($this->bots),
            'NICK' => NetworkHelp::nick($this->nickServNick, $this->cregNick),
            'CANAL' => NetworkHelp::canal($this->cregNick, $this->chanNick),
            'CREDITOS' => NetworkHelp::creditos(),
            default => [sprintf(
                'Comando desconocido %s. %s para ayuda.',
                IrcFormat::paint(IrcFormat::COMMAND, $command),
                IrcFormat::paint(IrcFormat::COMMAND, sprintf('/msg %s AYUDA', $this->serviceNick)),
            )],
        };
    }

    /** @param list<string> $lines */
    private function say(string $uid, array $lines): void
    {
        foreach ($lines as $line) {
            $this->reply->reply($uid, $line);
        }
    }

    private function isAddressedToMe(string $target): bool
    {
        return 0 === strcasecmp($target, $this->serviceNick) || $target === $this->serviceUid;
    }

    /** @return list<string> */
    private function tokenise(string $text): array
    {
        return array_values(array_filter(
            explode(' ', $text),
            static fn (string $token): bool => '' !== $token,
        ));
    }
}
