<?php

declare(strict_types=1);

namespace Dbots\GlobalServ\Adapter\In\Irc;

use Dbots\Application\Port\Out\LinkTransportInterface;
use Dbots\Domain\Account\Nick;
use Dbots\Domain\Staff\StaffRank;
use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Adapter\Out\Irc\ServiceUidFormatter;
use Dbots\Irc\Application\DebugRelay;
use Dbots\Irc\Application\Help\HelpCatalog;
use Dbots\Irc\Application\NetworkState;
use Dbots\NickServ\Application\Port\Out\AccountRepositoryInterface;
use Dbots\NickServ\Application\Port\Out\ReplyInterface;
use Dbots\OperServ\Application\Port\Out\EnforcementInterface;
use Dbots\Staff\Application\StaffAuthority;
use InvalidArgumentException;

use function array_filter;
use function array_shift;
use function array_values;
use function count;
use function explode;
use function implode;
use function md5;
use function microtime;
use function sprintf;
use function strcasecmp;
use function strtoupper;
use function substr;

final readonly class GlobalServCommandHandler
{
    private string $sid;
    private ServiceUidFormatter $uidFormatter;

    public function __construct(
        private LinkTransportInterface $transport,
        private ReplyInterface $reply,
        private StaffAuthority $staff,
        private AccountRepositoryInterface $accounts,
        private EnforcementInterface $enforcement,
        private string $serviceNick,
        private string $serviceUid,
        private string $serviceHost,
        private HelpCatalog $help,
        private ?DebugRelay $debug = null,
    ) {
        $this->sid = substr($this->serviceUid, 0, 3);
        $this->uidFormatter = new ServiceUidFormatter($this->sid);
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
            return;
        }

        $command = strtoupper((string) array_shift($parts));

        match ($command) {
            'GLOBAL' => $this->onGlobal($sender, $parts, $state),
            'NICK' => $this->onNick($sender, $parts, $state),
            'CHAN' => $this->onChan($sender, $parts, $state),
            'JOIN' => $this->onJoin($sender, $parts, $state),
            'PRE-OPER' => $this->onRank($sender, $parts, $state, StaffRank::PreOperador, 'Pre-Oper'),
            'OPER' => $this->onRank($sender, $parts, $state, StaffRank::Operador, 'Oper'),
            'CO-ADMIN' => $this->onRank($sender, $parts, $state, StaffRank::Administrador, 'Co-Admin', rootOnly: true),
            'ADMIN' => $this->onRank($sender, $parts, $state, StaffRank::Administrador, 'Admin', rootOnly: true, exactRank: true),
            'HELP', 'AYUDA' => $this->onHelp($sender, $parts, $state),
            default => $this->reply->reply($sender, sprintf(
                'Comando desconocido %s. /msg %s AYUDA para ayuda.',
                $command,
                $this->serviceNick,
            )),
        };
    }

    /** @param list<string> $args */
    private function onGlobal(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireAdmin($sender, $state)) {
            return;
        }

        $text = implode(' ', $args);

        if ('' === $text) {
            $this->reply->reply($sender, 'Error de Sintaxis: GLOBAL <mensaje>');

            return;
        }

        $count = $this->broadcast($state->users(), $text, "\x033[ Mensaje Global ]\x03", $this->serviceUid);
        $this->confirm($sender, $count);
        $this->logCommand($sender, 'GLOBAL');
    }

    /** @param list<string> $args */
    private function onNick(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireAdmin($sender, $state)) {
            return;
        }

        $nick = array_shift($args);
        $text = implode(' ', $args);

        if (null === $nick || '' === $text) {
            $this->reply->reply($sender, 'Error de Sintaxis: NICK <nick> <mensaje>');

            return;
        }

        try {
            $accountNick = new Nick($nick);
        } catch (InvalidArgumentException) {
            $this->reply->reply($sender, sprintf('El nick %s no es válido.', $nick));

            return;
        }

        if (null !== $this->accounts->findByNick($accountNick)) {
            $this->reply->reply($sender, sprintf('El nick %s está registrado.', $nick));

            return;
        }

        if ($state->hasNick($nick)) {
            $this->reply->reply($sender, sprintf('El nick %s actualmente está en uso.', $nick));

            return;
        }

        $tempUid = $this->allocateTempUid($nick);

        $this->transport->writeLine($this->uidFormatter->format(
            nick: $nick,
            uid: $tempUid,
            realname: 'Network Global Announcement Services',
            ident: $nick,
            host: $this->serviceHost,
        ));

        $count = $this->broadcast($state->users(), $text, "\x033[ Mensaje Global ]\x03", $tempUid);

        $this->transport->writeLine(sprintf(':%s QUIT :', $tempUid));
        $this->confirm($sender, $count);
        $this->logCommand($sender, 'NICK', $nick);
    }

    /** @param list<string> $args */
    private function onChan(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireAdmin($sender, $state)) {
            return;
        }

        $channel = array_shift($args);
        $text = implode(' ', $args);

        if (null === $channel || '' === $text) {
            $this->reply->reply($sender, 'Error de Sintaxis: CHAN <#canal> <mensaje>');

            return;
        }

        if (!str_starts_with($channel, '#')) {
            $this->reply->reply($sender, 'El canal debe comenzar por #.');

            return;
        }

        $members = $state->membersOf($channel);

        if ([] === $members) {
            $this->reply->reply($sender, sprintf('El canal %s no está en uso en este momento.', $channel));

            return;
        }

        $recipients = [];

        foreach ($members as $uid) {
            $nick = $state->nickOf($uid);

            if (null !== $nick) {
                $recipients[$uid] = $nick;
            }
        }

        $count = $this->broadcast($recipients, $text, "\x033[ Mensaje Global ]\x03", $this->serviceUid);
        $this->confirm($sender, $count);
        $this->logCommand($sender, 'CHAN', $channel);
    }

    /** @param list<string> $args */
    private function onJoin(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireRoot($sender, $state)) {
            return;
        }

        $channel = array_shift($args);
        $text = implode(' ', $args);

        if (null === $channel || '' === $text) {
            $this->reply->reply($sender, 'Error de Sintaxis: JOIN <#canal> <motivo>');

            return;
        }

        if (!str_starts_with($channel, '#')) {
            $this->reply->reply($sender, 'El canal debe comenzar por #.');

            return;
        }

        foreach ($state->users() as $uid => $nick) {
            $this->enforcement->svsJoin($uid, $channel);
        }

        $count = $this->broadcast($state->users(), $text, "\x033[ Mensaje Global ]\x03", $this->serviceUid);
        $this->confirm($sender, $count);
        $this->logCommand($sender, 'JOIN', $channel);
    }

    /**
     * @param list<string> $args
     */
    private function onRank(
        string $sender,
        array $args,
        NetworkState $state,
        StaffRank $rank,
        string $label,
        bool $rootOnly = false,
        bool $exactRank = false,
    ): void {
        if ($rootOnly && !$this->requireRoot($sender, $state)) {
            return;
        }

        if (!$rootOnly && !$this->requireAdmin($sender, $state)) {
            return;
        }

        $text = implode(' ', $args);

        if ('' === $text) {
            $this->reply->reply($sender, sprintf('Error de Sintaxis: %s <mensaje>', $label));

            return;
        }

        $recipients = [];

        foreach ($state->users() as $uid => $nick) {
            $account = $state->accountOf($uid);

            if ($exactRank) {
                if ($this->staff->levelOf($account) === $rank->level()) {
                    $recipients[$uid] = $nick;
                }

                continue;
            }

            if ($this->staff->atLeast($account, $rank)) {
                $recipients[$uid] = $nick;
            }
        }

        $count = $this->broadcast($recipients, $text, $this->rankHeader($label), $this->serviceUid);
        $this->confirm($sender, $count);
        $this->logCommand($sender, $label, $text);
    }

    /** @param list<string> $args */
    private function onHelp(string $sender, array $args, NetworkState $state): void
    {
        $isOper = $this->hasRank($sender, $state, StaffRank::Administrador);

        foreach ($this->help->render(implode(' ', $args), $isOper) as $line) {
            $this->reply->reply($sender, $line);
        }
    }

    /**
     * @param array<string, string> $recipients uid => nick
     */
    private function broadcast(array $recipients, string $text, string $header, string $sourceUid): int
    {
        foreach ($recipients as $nick) {
            $this->transport->writeLine(sprintf(':%s PRIVMSG %s :%s', $sourceUid, $nick, $header));
            $this->transport->writeLine(sprintf(':%s PRIVMSG %s :%s', $sourceUid, $nick, $text));
        }

        return count($recipients);
    }

    private function confirm(string $sender, int $count): void
    {
        $this->reply->reply($sender, 'Mensaje global enviado correctamente.');
        $this->reply->reply($sender, sprintf('Número de Impactos: %d', $count));
    }

    private function rankHeader(string $label): string
    {
        return match ($label) {
            'Pre-Oper' => "\x033[ Mensaje Global para \x0312Pre-Oper\x033adores (Y superiores) ]\x03",
            'Oper' => "\x033[ Mensaje Global para \x0312Oper\x033adores (Y superiores) ]\x03",
            'Co-Admin' => "\x033[ Mensaje Global para \x0312Co-Admin\x033istradores (Y superiores) ]\x03",
            'Admin' => "\x033[ Mensaje Global para \x0312Admin\x033istradores ]\x03",
            default => "\x033[ Mensaje Global ]\x03",
        };
    }

    private function requireAdmin(string $sender, NetworkState $state): bool
    {
        if ($this->hasRank($sender, $state, StaffRank::Administrador)) {
            return true;
        }

        $this->reply->reply($sender, 'Permiso denegado.');

        return false;
    }

    private function requireRoot(string $sender, NetworkState $state): bool
    {
        if ($this->hasRank($sender, $state, StaffRank::Root)) {
            return true;
        }

        $this->reply->reply($sender, 'Permiso denegado.');

        return false;
    }

    private function hasRank(string $uid, NetworkState $state, StaffRank $rank): bool
    {
        return $this->staff->atLeast($this->resolveAccount($uid, $state), $rank);
    }

    private function resolveAccount(string $uid, NetworkState $state): ?string
    {
        if (!$state->hasMode($uid, 'r')) {
            return null;
        }

        return $state->accountOf($uid);
    }

    private function allocateTempUid(string $nick): string
    {
        $hash = strtoupper(substr(md5($nick . (string) microtime(true)), 0, 5));

        return sprintf('%sA9%s', $this->sid, $hash);
    }

    private function logCommand(string $sender, string $verb, string $arguments = ''): void
    {
        if (null === $this->debug || !$this->debug->isEnabled()) {
            return;
        }

        $this->debug->command($sender, $verb, 'GlobalServ', $arguments);
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
