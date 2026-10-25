<?php

declare(strict_types=1);

namespace Dbots\CRegServ\Adapter\In\Irc;

use DateTimeImmutable;
use Dbots\CRegServ\Application\Approve\ApproveHandler;
use Dbots\CRegServ\Application\Approve\ApproveResult;
use Dbots\CRegServ\Application\Cancel\CancelHandler;
use Dbots\CRegServ\Application\Deny\DenyHandler;
use Dbots\CRegServ\Application\Deny\DenyResult;
use Dbots\CRegServ\Application\Port\Out\ChannelRequestRepositoryInterface;
use Dbots\CRegServ\Application\Request\RequestRegistrationHandler;
use Dbots\CRegServ\Application\Request\RequestRegistrationResult;
use Dbots\ChanServ\Application\Port\Out\ChannelProjectionInterface;
use Dbots\ChanServ\Application\Port\Out\ChannelRepositoryInterface;
use Dbots\Domain\Channel\Channel;
use Dbots\Domain\Channel\ChannelName;
use Dbots\Domain\Registration\ChannelKind;
use Dbots\Domain\Registration\ChannelRequest;
use Dbots\Domain\Registration\RequestStatus;
use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\AccountResolver;
use Dbots\Irc\Application\Help\HelpCatalog;
use Dbots\Irc\Application\NetworkState;
use Dbots\NickServ\Application\Port\Out\ReplyInterface;
use InvalidArgumentException;

use function array_filter;
use function array_shift;
use function array_values;
use function explode;
use function implode;
use function sprintf;
use function strcasecmp;
use function strtoupper;

final readonly class CRegServCommandHandler
{
    public function __construct(
        private RequestRegistrationHandler $request,
        private ApproveHandler $approve,
        private DenyHandler $deny,
        private CancelHandler $cancel,
        private ChannelRequestRepositoryInterface $requests,
        private ChannelRepositoryInterface $channels,
        private ChannelProjectionInterface $projection,
        private AccountResolver $accounts,
        private ReplyInterface $reply,
        private string $serviceNick,
        private string $serviceUid,
        private HelpCatalog $help,
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
            return;
        }

        $command = strtoupper((string) array_shift($parts));
        $account = $this->accounts->resolve($sender, $state);
        $staff = $this->isStaff($sender, $account, $state);

        match ($command) {
            'REGISTRA', 'REGISTER' => $this->onRequest($sender, $account, $parts, $state),
            'ESTADO' => $this->onStatus($sender, $parts),
            'CANCELA' => $this->onCancel($sender, $account, $parts),
            'ACEPTA', 'APROBAR' => $this->onApprove($sender, $parts, $state, $staff),
            'DENIEGA', 'DENEGAR' => $this->onDeny($sender, $parts, $state, $staff),
            'LIBERA' => $this->onRelease($sender, $parts, $staff),
            'LISTA', 'PENDIENTES' => $this->onList($sender, $parts, $staff),
            'INFO' => $this->onInfo($sender, $parts, $staff),
            'MARCA' => $this->onMark($sender, $parts, $state, $staff),
            'ADMIN' => $this->onAdmin($sender, $parts, $staff),
            'OFICIAL' => $this->onSpecial($sender, $parts, $staff, ChannelKind::Official),
            'COMERCIAL' => $this->onSpecial($sender, $parts, $staff, ChannelKind::Commercial),
            'HELP', 'AYUDA' => $this->onHelp($sender, $parts, $state),
            default => $this->reply->reply($sender, sprintf('Comando desconocido %s. Usa AYUDA.', $command)),
        };
    }

    /** @param list<string> $args */
    private function onRequest(string $uid, ?string $account, array $args, NetworkState $state): void
    {
        $channel = array_shift($args);

        if (null === $channel) {
            $this->reply->reply($uid, 'Sintaxis: REGISTRA <#canal> [descripción]');

            return;
        }

        $operatorInChannel = null !== $account && $this->stateOperatorIn($channel, $uid, $state);
        $description = implode(' ', array_values($args));

        match (($this->request)($channel, $description, $account, $operatorInChannel)) {
            RequestRegistrationResult::Requested => $this->reply->reply($uid, sprintf(
                'Solicitud registrada para %s. El staff la revisará.',
                $channel,
            )),
            RequestRegistrationResult::NotIdentified => $this->reply->reply($uid, 'Debes estar identificado para solicitar un canal.'),
            RequestRegistrationResult::NotChannelOperator => $this->reply->reply($uid, sprintf('Debes tener @ en %s para pedir su registro.', $channel)),
            RequestRegistrationResult::InvalidChannel => $this->reply->reply($uid, 'Ese nombre de canal no es válido.'),
            RequestRegistrationResult::AlreadyRegistered => $this->reply->reply($uid, sprintf('%s ya está registrado.', $channel)),
            RequestRegistrationResult::DuplicateRequest => $this->reply->reply($uid, sprintf('Ya hay una solicitud pendiente para %s.', $channel)),
            RequestRegistrationResult::PreviouslyDenied => $this->reply->reply($uid, sprintf('Una solicitud anterior para %s fue denegada.', $channel)),
        };
    }

    /** @param list<string> $args */
    private function onStatus(string $uid, array $args): void
    {
        $channel = array_shift($args);

        if (null === $channel) {
            $this->reply->reply($uid, 'Sintaxis: ESTADO <#canal>');

            return;
        }

        $request = $this->requests->find($channel);

        if (null === $request) {
            $this->reply->reply($uid, sprintf('%s no está registrado ni en trámite.', $channel));

            return;
        }

        $this->reply->reply($uid, sprintf(
            '%s: %s — solicitado por %s el %s%s.',
            $request->channel,
            $request->status->value,
            $request->requester,
            $request->requestedAt->format('Y-m-d'),
            null === $request->reason ? '' : ' — ' . $request->reason,
        ));
    }

    /** @param list<string> $args */
    private function onCancel(string $uid, ?string $account, array $args): void
    {
        $channel = array_shift($args);

        if (null === $channel) {
            $this->reply->reply($uid, 'Sintaxis: CANCELA <#canal>');

            return;
        }

        $this->reply->reply($uid, ($this->cancel)($channel, $account)
            ? sprintf('Solicitud de %s retirada.', $channel)
            : sprintf('No tienes una solicitud pendiente para %s.', $channel));
    }

    /** @param list<string> $args */
    private function onApprove(string $uid, array $args, NetworkState $state, bool $staff): void
    {
        if (!$this->requireStaff($uid, $staff)) {
            return;
        }

        $channel = array_shift($args);

        if (null === $channel) {
            $this->reply->reply($uid, 'Sintaxis: ACEPTA <#canal>');

            return;
        }

        $staffNick = $state->nickOf($uid) ?? 'staff';

        match (($this->approve)($channel, $staffNick)) {
            ApproveResult::Approved => $this->reply->reply($uid, sprintf('%s registrado.', $channel)),
            ApproveResult::NoPendingRequest => $this->reply->reply($uid, sprintf('No hay solicitud pendiente para %s.', $channel)),
            ApproveResult::AlreadyRegistered => $this->reply->reply($uid, sprintf('%s ya está registrado.', $channel)),
            ApproveResult::InvalidChannel => $this->reply->reply($uid, 'Ese nombre de canal no es válido.'),
        };
    }

    /** @param list<string> $args */
    private function onDeny(string $uid, array $args, NetworkState $state, bool $staff): void
    {
        if (!$this->requireStaff($uid, $staff)) {
            return;
        }

        $channel = array_shift($args);

        if (null === $channel) {
            $this->reply->reply($uid, 'Sintaxis: DENIEGA <#canal> [motivo]');

            return;
        }

        $staffNick = $state->nickOf($uid) ?? 'staff';
        $reason = implode(' ', array_values($args));

        match (($this->deny)($channel, $staffNick, $reason)) {
            DenyResult::Denied => $this->reply->reply($uid, sprintf('Solicitud de %s denegada.', $channel)),
            DenyResult::NoPendingRequest => $this->reply->reply($uid, sprintf('No hay solicitud pendiente para %s.', $channel)),
            DenyResult::InvalidChannel => $this->reply->reply($uid, 'Ese nombre de canal no es válido.'),
        };
    }

    /** @param list<string> $args */
    private function onRelease(string $uid, array $args, bool $staff): void
    {
        if (!$this->requireStaff($uid, $staff)) {
            return;
        }

        $channel = array_shift($args);
        $request = null === $channel ? null : $this->requests->find($channel);

        if (null === $request || null === $channel) {
            $this->reply->reply($uid, 'Sintaxis: LIBERA <#canal> (debe estar denegado)');

            return;
        }

        if (RequestStatus::Denied !== $request->status) {
            $this->reply->reply($uid, sprintf('%s no está denegado.', $channel));

            return;
        }

        $this->requests->delete($request->channel);
        $this->reply->reply($uid, sprintf('%s liberado.', $channel));
    }

    /** @param list<string> $args */
    private function onList(string $uid, array $args, bool $staff): void
    {
        if (!$this->requireStaff($uid, $staff)) {
            return;
        }

        $wanted = array_shift($args);
        $status = null === $wanted ? null : RequestStatus::tryFrom(strtoupper($wanted));
        $requests = null === $status ? $this->requests->all() : $this->requests->byStatus($status);

        if ([] === $requests) {
            $this->reply->reply($uid, 'No hay solicitudes.');

            return;
        }

        foreach ($requests as $request) {
            $this->reply->reply($uid, sprintf(
                '%s — %s (%s, %s)',
                $request->channel,
                $request->status->value,
                $request->requester,
                $request->requestedAt->format('Y-m-d'),
            ));
        }
    }

    /** @param list<string> $args */
    private function onInfo(string $uid, array $args, bool $staff): void
    {
        if (!$this->requireStaff($uid, $staff)) {
            return;
        }

        $channel = array_shift($args);
        $request = null === $channel ? null : $this->requests->find($channel);

        if (null === $request || null === $channel) {
            $this->reply->reply($uid, 'Sintaxis: INFO <#canal>');

            return;
        }

        $this->reply->reply($uid, sprintf('Canal: %s (%s)', $request->channel, $request->kind->value));
        $this->reply->reply($uid, sprintf('Fundador: %s — solicitado el %s', $request->requester, $request->requestedAt->format('Y-m-d H:i')));
        $this->reply->reply($uid, sprintf('Estado: %s', $request->status->value));
        $this->reply->reply($uid, sprintf('Descripción: %s', $request->description));

        foreach ($this->requests->marksOf($request->channel) as $mark) {
            $this->reply->reply($uid, 'Marca: ' . $mark);
        }
    }

    /** @param list<string> $args */
    private function onMark(string $uid, array $args, NetworkState $state, bool $staff): void
    {
        if (!$this->requireStaff($uid, $staff)) {
            return;
        }

        $action = strtoupper((string) array_shift($args));
        $channel = array_shift($args);
        $text = implode(' ', array_values($args));

        if ('ADD' !== $action || null === $channel || '' === $text) {
            $this->reply->reply($uid, 'Sintaxis: MARCA ADD <#canal> <texto>');

            return;
        }

        if (null === $this->requests->find($channel)) {
            $this->reply->reply($uid, sprintf('%s no existe en CReG.', $channel));

            return;
        }

        $this->requests->addMark($channel, $text, $state->nickOf($uid) ?? 'staff');
        $this->reply->reply($uid, sprintf('Marca añadida a %s.', $channel));
    }

    /** @param list<string> $args */
    private function onAdmin(string $uid, array $args, bool $staff): void
    {
        if (!$this->requireStaff($uid, $staff)) {
            return;
        }

        $action = strtoupper((string) array_shift($args));
        $nick = array_shift($args);

        match ($action) {
            'ADD' => null === $nick
                ? $this->reply->reply($uid, 'Sintaxis: ADMIN ADD <nick>')
                : $this->addAdmin($uid, $nick),
            'DEL' => null === $nick
                ? $this->reply->reply($uid, 'Sintaxis: ADMIN DEL <nick>')
                : $this->reply->reply($uid, $this->requests->removeAdmin($nick)
                    ? sprintf('%s ya no es administrador de CReG.', $nick)
                    : sprintf('%s no era administrador.', $nick)),
            'LIST' => $this->reply->reply($uid, [] === $this->requests->admins()
                ? 'No hay administradores de CReG.'
                : 'Administradores: ' . implode(', ', $this->requests->admins())),
            default => $this->reply->reply($uid, 'Sintaxis: ADMIN ADD|DEL|LIST [nick]'),
        };
    }

    private function addAdmin(string $uid, string $nick): void
    {
        $this->requests->addAdmin($nick);
        $this->reply->reply($uid, sprintf('%s es ahora administrador de CReG.', $nick));
    }

    /** @param list<string> $args */
    private function onSpecial(string $uid, array $args, bool $staff, ChannelKind $kind): void
    {
        if (!$this->requireStaff($uid, $staff)) {
            return;
        }

        $channel = array_shift($args);
        $founder = ChannelKind::Commercial === $kind ? array_shift($args) : null;
        $description = implode(' ', array_values($args));

        if (null === $channel || (ChannelKind::Commercial === $kind && null === $founder)) {
            $this->reply->reply($uid, ChannelKind::Commercial === $kind
                ? 'Sintaxis: COMERCIAL <#canal> <fundador> [descripción]'
                : 'Sintaxis: OFICIAL <#canal> [descripción]');

            return;
        }

        try {
            $name = new ChannelName($channel);
        } catch (InvalidArgumentException) {
            $this->reply->reply($uid, 'Ese nombre de canal no es válido.');

            return;
        }

        if (null !== $this->channels->find($name)) {
            $this->reply->reply($uid, sprintf('%s ya está registrado.', $channel));

            return;
        }

        $this->channels->save(new Channel($name, $founder ?? $uid, new DateTimeImmutable()));
        $this->projection->publishRegistered($name->value);

        $this->requests->save(new ChannelRequest(
            $name->value,
            $founder ?? $uid,
            '' === $description ? 'Sin descripción' : $description,
            RequestStatus::Approved,
            new DateTimeImmutable(),
            $kind,
        ));

        $this->reply->reply($uid, sprintf('%s registrado como %s.', $name->value, $kind->value));
    }

    /** @param list<string> $args */
    private function onHelp(string $uid, array $args, NetworkState $state): void
    {
        $isOper = $state->hasMode($uid, 'o');

        foreach ($this->help->render(implode(' ', $args), $isOper) as $line) {
            $this->reply->reply($uid, $line);
        }
    }

    private function stateOperatorIn(string $channel, string $uid, NetworkState $state): bool
    {
        return $state->isChannelOperator($channel, $uid);
    }

    private function isStaff(string $sender, ?string $account, NetworkState $state): bool
    {
        if ($state->hasMode($sender, 'o')) {
            return true;
        }

        return null !== $account && $this->requests->isAdmin($account);
    }

    private function requireStaff(string $uid, bool $staff): bool
    {
        if ($staff) {
            return true;
        }

        $this->reply->reply($uid, 'Ese comando es para el staff.');

        return false;
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
