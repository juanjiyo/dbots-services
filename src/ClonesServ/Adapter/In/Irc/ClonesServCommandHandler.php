<?php

declare(strict_types=1);

namespace Dbots\ClonesServ\Adapter\In\Irc;

use DateTimeImmutable;
use DateTimeZone;
use Dbots\ClonesServ\Application\ClonesDuration;
use Dbots\ClonesServ\Application\Help\ClonesServHelp;
use Dbots\ClonesServ\Application\Port\Out\ClonesExceptionRepositoryInterface;
use Dbots\ClonesServ\Application\Port\Out\ClonesWriterInterface;
use Dbots\ClonesServ\Domain\ClonesException;
use Dbots\Domain\Staff\StaffRank;
use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\AccountResolver;
use Dbots\Irc\Application\Help\IrcFormat;
use Dbots\Irc\Application\NetworkState;
use Dbots\NickServ\Application\Port\Out\ReplyInterface;
use Dbots\Staff\Application\StaffAuthority;
use RuntimeException;

use function array_filter;
use function array_shift;
use function array_values;
use function explode;
use function filter_var;
use function preg_match;
use function sprintf;
use function strcasecmp;
use function strtoupper;

final readonly class ClonesServCommandHandler
{
    public function __construct(
        private ClonesExceptionRepositoryInterface $repository,
        private ClonesWriterInterface $writer,
        private StaffAuthority $staff,
        private AccountResolver $resolver,
        private ReplyInterface $reply,
        private string $serviceNick,
        private string $serviceUid,
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
            $this->sendHelp($sender);

            return;
        }

        $command = strtoupper((string) array_shift($parts));

        if ('HELP' === $command || 'AYUDA' === $command) {
            $this->sendHelp($sender);

            return;
        }

        if (!$this->requireAdmin($sender, $state)) {
            return;
        }

        match ($command) {
            'PERM' => $this->onPerm($sender, $parts, $state),
            'ADD' => $this->onAdd($sender, $parts, $state),
            'RENEW' => $this->onRenew($sender, $parts, $state),
            'EDIT' => $this->onEdit($sender, $parts, $state),
            'DEL' => $this->onDel($sender, $parts),
            'INFO' => $this->onInfo($sender, $parts, $state),
            'LIST' => $this->onList($sender),
            default => $this->reply($sender, sprintf(
                'Comando no reconocido. Usa %s.',
                IrcFormat::paint(IrcFormat::COMMAND, 'HELP')
            )),
        };
    }

    public function cleanup(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        foreach ($this->repository->all() as $exception) {
            if ($exception->isExpired($now)) {
                $this->repository->deleteByHost($exception->host);
            }
        }

        try {
            $this->writer->write($this->repository->allActive($now));
        } catch (RuntimeException $error) {
            fwrite(STDERR, sprintf("CLoNeS cleanup failed: %s\n", $error->getMessage()));
        }
    }

    /** @param list<string> $args */
    private function onPerm(string $sender, array $args, NetworkState $state): void
    {
        $host = array_shift($args);
        $clonesValue = array_shift($args);

        if (null === $host || null === $clonesValue || [] !== $args) {
            $this->reply($sender, 'Sintaxis: PERM <IP|host> <N>');

            return;
        }

        $clones = $this->parseClones($clonesValue);

        if (null === $clones) {
            $this->reply($sender, 'Número de clones no válido.');

            return;
        }

        if (!$this->isValidHost($host)) {
            $this->reply($sender, 'IP/Host no válido.');

            return;
        }

        if (null !== $this->repository->findByHost($host)) {
            $this->reply($sender, sprintf(
                'Ya existe una entrada para %s. Usa %s para modificarla o %s para eliminarla primero.',
                $host,
                IrcFormat::paint(IrcFormat::COMMAND, "edit {$host} {$clonesValue} <tiempo>"),
                IrcFormat::paint(IrcFormat::COMMAND, "del {$host}")
            ));

            return;
        }

        $exception = new ClonesException(
            null,
            $host,
            $clones,
            'perm',
            $this->nickOf($sender, $state) ?? $sender,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            null,
        );

        $this->repository->save($exception);
        $this->repository->linkNick($exception->author, $host);
        $this->writeConfig($sender);

        $this->reply($sender, sprintf(
            'Entrada PERM añadida para %s (%d clones).',
            $host,
            $clones
        ));
    }

    /** @param list<string> $args */
    private function onAdd(string $sender, array $args, NetworkState $state): void
    {
        $host = array_shift($args);
        $clonesValue = array_shift($args);
        $durationValue = array_shift($args);

        if (null === $host || null === $clonesValue || null === $durationValue || [] !== $args) {
            $this->reply($sender, 'Sintaxis: ADD <IP|host> <N> <tiempo>');

            return;
        }

        $clones = $this->parseClones($clonesValue);

        if (null === $clones) {
            $this->reply($sender, 'Número de clones no válido.');

            return;
        }

        if (!$this->isValidHost($host)) {
            $this->reply($sender, 'IP/Host no válido.');

            return;
        }

        $expiresAt = ClonesDuration::parse($durationValue);

        if (null === $expiresAt) {
            $this->reply($sender, 'Formato de tiempo no válido. Use: 30d, 6m, 1y');

            return;
        }

        if (null !== $this->repository->findByHost($host)) {
            $this->reply($sender, sprintf(
                'Ya existe una entrada para %s. Usa %s para modificarla o %s para eliminarla primero.',
                $host,
                IrcFormat::paint(IrcFormat::COMMAND, "edit {$host} {$clonesValue} <tiempo>"),
                IrcFormat::paint(IrcFormat::COMMAND, "del {$host}")
            ));

            return;
        }

        $exception = new ClonesException(
            null,
            $host,
            $clones,
            'temp',
            $this->nickOf($sender, $state) ?? $sender,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $expiresAt,
        );

        $this->repository->save($exception);
        $this->repository->linkNick($exception->author, $host);
        $this->writeConfig($sender);

        $this->reply($sender, sprintf(
            'Entrada TEMP añadida para %s (%d clones).',
            $host,
            $clones
        ));
    }

    /** @param list<string> $args */
    private function onRenew(string $sender, array $args, NetworkState $state): void
    {
        $host = array_shift($args);
        $durationValue = array_shift($args);

        if (null === $host || null === $durationValue || [] !== $args) {
            $this->reply($sender, 'Sintaxis: RENEW <IP|host> <tiempo>');

            return;
        }

        $existing = $this->repository->findByHost($host);

        if (null === $existing) {
            $this->reply($sender, sprintf('No existe entrada para %s.', $host));

            return;
        }

        $expiresAt = ClonesDuration::parse($durationValue);

        if (null === $expiresAt) {
            $this->reply($sender, 'Formato de tiempo no válido. Use: 30d, 6m, 1y');

            return;
        }

        $exception = new ClonesException(
            $existing->id,
            $existing->host,
            $existing->clones,
            $existing->type,
            $this->nickOf($sender, $state) ?? $sender,
            $existing->createdAt,
            $expiresAt,
        );

        $this->repository->save($exception);
        $this->repository->linkNick($exception->author, $host);
        $this->writeConfig($sender);

        $this->reply($sender, sprintf(
            'Entrada renovada para %s hasta %s.',
            $host,
            $this->formatDate($exception->expiresAt)
        ));
    }

    /** @param list<string> $args */
    private function onEdit(string $sender, array $args, NetworkState $state): void
    {
        $host = array_shift($args);
        $clonesValue = array_shift($args);
        $durationValue = array_shift($args);

        if (null === $host || null === $clonesValue || null === $durationValue || [] !== $args) {
            $this->reply($sender, 'Sintaxis: EDIT <IP|host> <N> <tiempo>');

            return;
        }

        $existing = $this->repository->findByHost($host);

        if (null === $existing) {
            $this->reply($sender, sprintf('No existe entrada para %s.', $host));

            return;
        }

        $clones = $this->parseClones($clonesValue);

        if (null === $clones) {
            $this->reply($sender, 'Número de clones no válido.');

            return;
        }

        $expiresAt = ClonesDuration::parse($durationValue);

        if (null === $expiresAt) {
            $this->reply($sender, 'Formato de tiempo no válido. Use: 30d, 6m, 1y');

            return;
        }

        $exception = new ClonesException(
            $existing->id,
            $existing->host,
            $clones,
            $existing->type,
            $this->nickOf($sender, $state) ?? $sender,
            $existing->createdAt,
            $expiresAt,
        );

        $this->repository->save($exception);
        $this->repository->linkNick($exception->author, $host);
        $this->writeConfig($sender);

        $this->reply($sender, sprintf(
            'Entrada editada para %s (%d clones, hasta %s).',
            $host,
            $clones,
            $this->formatDate($exception->expiresAt)
        ));
    }

    /** @param list<string> $args */
    private function onDel(string $sender, array $args): void
    {
        $host = array_shift($args);

        if (null === $host || [] !== $args) {
            $this->reply($sender, 'Sintaxis: DEL <IP|host>');

            return;
        }

        if (!$this->repository->deleteByHost($host)) {
            $this->reply($sender, sprintf('No existe entrada para %s.', $host));

            return;
        }

        $this->writeConfig($sender);

        $this->reply($sender, sprintf('Entrada eliminada para %s.', $host));
    }

    /** @param list<string> $args */
    private function onInfo(string $sender, array $args, NetworkState $state): void
    {
        if (null !== ($host = array_shift($args))) {
            $this->showInfo($sender, $host);

            return;
        }

        $nick = $this->nickOf($sender, $state) ?? $sender;
        $host = $this->repository->hostForNick($nick);

        if (null === $host) {
            $this->reply($sender, 'No tienes una IP asignada. Usa: info <IP>');

            return;
        }

        $this->showInfo($sender, $host);
    }

    private function showInfo(string $sender, string $host): void
    {
        $exception = $this->repository->findByHost($host);

        if (null === $exception) {
            $this->reply($sender, 'IP no encontrada.');

            return;
        }

        $p = ClonesServHelp::prefix();
        $this->say($sender, $p . ' Información de la iline:');
        $this->say($sender, sprintf('%s %s %s', $p, IrcFormat::paint(IrcFormat::COMMAND, 'IP:'), $exception->host));
        $this->say($sender, sprintf('%s %s %s', $p, IrcFormat::paint(IrcFormat::COMMAND, 'Clones:'), $exception->clones));
        $this->say($sender, sprintf('%s %s %s', $p, IrcFormat::paint(IrcFormat::COMMAND, 'Estado:'), 'Active'));
        $this->say($sender, sprintf(
            '%s %s %s',
            $p,
            IrcFormat::paint(IrcFormat::COMMAND, 'Expiración:'),
            $this->formatDate($exception->expiresAt)
        ));
        $this->say($sender, $p . ' Fin de la información.');
    }

    private function onList(string $sender): void
    {
        $exceptions = $this->repository->allActive(new DateTimeImmutable('now', new DateTimeZone('UTC')));

        if ([] === $exceptions) {
            $this->reply($sender, 'No hay entradas.');

            return;
        }

        $p = ClonesServHelp::prefix();
        $this->say($sender, $p . ' ' . IrcFormat::paint(IrcFormat::COMMAND, 'Lista de ilines activas:'));

        foreach ($exceptions as $exception) {
            $this->say($sender, sprintf(
                '%s %s → %d clones (%s)',
                $p,
                $exception->host,
                $exception->clones,
                strtoupper($exception->type)
            ));
        }
    }

    private function requireAdmin(string $sender, NetworkState $state): bool
    {
        $account = $this->resolver->resolve($sender, $state);

        if ($this->staff->atLeast($account, StaffRank::Administrador)) {
            return true;
        }

        $this->reply($sender, 'Ese comando es para la administración.');

        return false;
    }

    private function writeConfig(string $sender): void
    {
        try {
            $rehashed = $this->writer->write($this->repository->allActive(new DateTimeImmutable('now', new DateTimeZone('UTC'))));

            if (!$rehashed) {
                $this->reply($sender, 'Aviso: no se encontró el PID de InspIRCd; los cambios se aplicarán al reiniciar.');
            }
        } catch (RuntimeException $error) {
            $this->reply($sender, sprintf('Aviso: %s', $error->getMessage()));
        }
    }

    private function nickOf(string $uid, NetworkState $state): ?string
    {
        return $state->nickOf($uid);
    }

    private function isValidHost(string $host): bool
    {
        return 1 === preg_match('/^[\w.\-:]+$/', $host);
    }

    private function parseClones(string $value): ?int
    {
        $clones = filter_var($value, FILTER_VALIDATE_INT);

        if (false === $clones || $clones < 1) {
            return null;
        }

        return $clones;
    }

    private function formatDate(?DateTimeImmutable $date): string
    {
        return null === $date ? 'Permanente' : $date->format('d/m/Y H:i:s');
    }

    private function sendHelp(string $sender): void
    {
        foreach (ClonesServHelp::render() as $line) {
            $this->say($sender, $line);
        }
    }

    private function reply(string $target, string $text): void
    {
        $this->say($target, ClonesServHelp::prefix() . ' ' . $text);
    }

    private function say(string $target, string $text): void
    {
        $this->reply->reply($target, $text);
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
