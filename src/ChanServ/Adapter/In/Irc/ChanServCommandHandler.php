<?php

declare(strict_types=1);

namespace Dbots\ChanServ\Adapter\In\Irc;

use Dbots\ChanServ\Application\DropChannel\DropChannelHandler;
use Dbots\ChanServ\Application\DropChannel\DropChannelResult;
use Dbots\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use Dbots\ChanServ\Application\Port\Out\ChannelActionsInterface;
use Dbots\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use Dbots\ChanServ\Application\Port\Out\ChannelProjectionInterface;
use Dbots\ChanServ\Application\Port\Out\ChannelRepositoryInterface;
use Dbots\Domain\Account\Nick;
use Dbots\Domain\Account\Password;
use Dbots\Domain\Channel\Channel;
use Dbots\Domain\Channel\ChannelLevel;
use Dbots\Domain\Channel\ChannelName;
use Dbots\Domain\Channel\ChannelSetting;
use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\Help\HelpCatalog;
use Dbots\Irc\Application\NetworkState;
use Dbots\Irc\Application\AccountResolver;
use Dbots\NickServ\Application\Port\Out\ReplyInterface;
use InvalidArgumentException;

use function array_filter;
use function array_shift;
use function array_values;
use function explode;
use function array_slice;
use function implode;
use function in_array;
use function preg_match;
use function sprintf;
use function strcasecmp;
use function str_replace;
use function strtoupper;

final class ChanServCommandHandler
{
    /** @var array<string, array<string, true>> channel => uid => identified */
    private array $identified = [];

    public function __construct(
        private DropChannelHandler $drop,
        private ChannelRepositoryInterface $channels,
        private ChannelAccessRepositoryInterface $access,
        private ChannelLevelRepositoryInterface $levels,
        private ChannelActionsInterface $actions,
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

        match ($command) {
            'REGISTER', 'REGISTRA' => $this->reply->reply($sender, 'El registro de canales se solicita a CReG: /msg CReG REGISTRA <#canal>'),
            'DROP' => $this->onDrop($sender, $account, $parts),
            'INFO' => $this->onInfo($sender, $parts),
            'IDENTIFY' => $this->onIdentify($sender, $parts),
            'ACCESS' => $this->onAccess($sender, $account, $parts, $state),
            'TOPIC' => $this->onTopic($sender, $account, $parts),
            'SET' => $this->onSet($sender, $account, $parts),
            'LEVELS' => $this->onLevels($sender, $account, $parts),
            'OP', 'DEOP', 'HALFOP', 'DEHALFOP', 'VOICE', 'DEVOICE' => $this->onRank($command, $sender, $account, $parts, $state),
            'INVITE' => $this->onInvite($sender, $account, $parts, $state),
            'UNBAN' => $this->onUnban($sender, $account, $parts, $state),
            'HELP', 'AYUDA' => $this->onHelp($sender, $parts, $state),
            default => $this->reply->reply($sender, 'Unknown command. Use HELP to list the available commands.'),
        };
    }

    /** @param list<string> $args */
    private function onDrop(string $uid, ?string $account, array $args): void
    {
        $channel = $args[0] ?? null;

        if (null === $channel) {
            $this->reply->reply($uid, 'Syntax: DROP <#channel>');

            return;
        }

        $result = ($this->drop)($channel, $account);

        if (DropChannelResult::Dropped === $result) {
            $this->projection->publishDropped($channel);
        }

        match ($result) {
            DropChannelResult::Dropped => $this->reply->reply($uid, sprintf('%s has been dropped.', $channel)),
            DropChannelResult::NotRegistered => $this->reply->reply($uid, sprintf('%s is not registered.', $channel)),
            DropChannelResult::NotFounder => $this->reply->reply($uid, 'Only the founder can drop a channel.'),
            DropChannelResult::InvalidChannel => $this->reply->reply($uid, 'That channel name cannot be registered.'),
        };
    }

    /** @param list<string> $args */
    private function onInfo(string $uid, array $args): void
    {
        $channel = $args[0] ?? null;

        if (null === $channel) {
            $this->reply->reply($uid, 'Syntax: INFO <#channel>');

            return;
        }

        try {
            $registered = $this->channels->find(new ChannelName($channel));
        } catch (InvalidArgumentException) {
            $this->reply->reply($uid, 'That channel name cannot be registered.');

            return;
        }

        if (null === $registered) {
            $this->reply->reply($uid, sprintf('%s is not registered.', $channel));

            return;
        }

        $this->reply->reply($uid, sprintf(
            '%s is registered to %s since %s.',
            $registered->name->value,
            $registered->founder,
            $registered->createdAt->format('Y-m-d'),
        ));

        foreach ([
            'Description' => $registered->description,
            'URL' => $registered->url,
            'Email' => $registered->email,
            'Entry message' => $registered->entryMessage,
        ] as $label => $value) {
            if (null !== $value && '' !== $value) {
                $this->reply->reply($uid, sprintf('%s: %s', $label, $value));
            }
        }
    }

    /** The channel password identifies its founder, as the original does. */
    private function onIdentify(string $uid, array $args): void
    {
        $channel = (string) ($args[0] ?? '');
        $password = (string) ($args[1] ?? '');

        if ('' === $channel || '' === $password) {
            $this->reply->reply($uid, 'Sintaxis: IDENTIFY <#canal> <contraseña>');

            return;
        }

        try {
            $registered = $this->channels->find(new ChannelName($channel));
        } catch (InvalidArgumentException) {
            $this->reply->reply($uid, 'Ese nombre de canal no es válido.');

            return;
        }

        if (null === $registered || null === $registered->passwordHash) {
            $this->reply->reply($uid, sprintf('%s no tiene contraseña de fundador.', $channel));

            return;
        }

        try {
            $secret = Password::fromPlain($password);
        } catch (InvalidArgumentException) {
            $this->reply->reply($uid, 'Contraseña incorrecta.');

            return;
        }

        if (!$registered->authenticatesWith($secret)) {
            $this->reply->reply($uid, 'Contraseña incorrecta.');

            return;
        }

        $this->identified[$registered->name->value][$uid] = true;
        $this->reply->reply($uid, sprintf('Estás identificado como fundador de %s.', $registered->name->value));
    }

    private function isIdentifiedFounder(string $uid, string $channel): bool
    {
        return isset($this->identified[$channel][$uid]);
    }

    /**
     * `chanserv.c.r` notices the entry message when somebody joins, replacing
     * `<nick>` with their nick. It needs no permission: it is a greeting.
     */
    public function announceEntry(string $uid, string $channel, NetworkState $state): void
    {
        try {
            $registered = $this->channels->find(new ChannelName($channel));
        } catch (InvalidArgumentException) {
            return;
        }

        $message = $registered?->entryMessage;

        if (null === $message || '' === $message) {
            return;
        }

        $this->reply->reply($uid, str_replace('<nick>', $state->nickOf($uid) ?? '', $message));
    }

    /** @param list<string> $args */
    /** @param list<string> $args */
    private function onAccess(string $uid, ?string $account, array $args, NetworkState $state): void
    {
        $action = strtoupper((string) ($args[0] ?? ''));
        $channel = (string) ($args[1] ?? '');

        if ('' === $channel || !in_array($action, ['LIST', 'ADD', 'DEL', 'DELETE'], true)) {
            $this->reply->reply($uid, 'Error de Sintaxis: ACCESS <canal> {ADD|DEL|LIST} <nick> <nivel>');

            return;
        }

        $registered = $this->registeredChannel($uid, $channel);

        if (null === $registered) {
            return;
        }

        match ($action) {
            'LIST' => $this->onAccessList($uid, $registered, $account, $state),
            'ADD' => $this->onAccessAdd(
                $uid,
                $registered,
                (string) ($args[2] ?? ''),
                (string) ($args[3] ?? ''),
                $account,
                $state,
            ),
            'DEL', 'DELETE' => $this->onAccessDel($uid, $registered, (string) ($args[2] ?? ''), $account, $state),
        };
    }

    private function onAccessList(string $uid, Channel $channel, ?string $account, NetworkState $state): void
    {
        if (!$this->requireLevel($uid, $channel, ChannelLevel::AccList, $this->authority($uid, $account, $channel, $state))) {
            return;
        }

        $entries = $this->access->entries($channel->name->value);

        $this->reply->reply($uid, sprintf('Lista de acceso de %s:', $channel->name->value));

        if ([] === $entries) {
            $this->reply->reply($uid, 'Lista vacía.');

            return;
        }

        foreach ($entries as $nick => $level) {
            $this->reply->reply($uid, sprintf('Nick: %s - Nivel: %d', $nick, $level));
        }

        $this->reply->reply($uid, 'Fin de la lista.');
    }

    private function onAccessAdd(
        string $uid,
        Channel $channel,
        string $nick,
        string $rawLevel,
        ?string $account,
        NetworkState $state,
    ): void {
        if (!$this->requireLevel($uid, $channel, ChannelLevel::AccChange, $this->authority($uid, $account, $channel, $state))) {
            return;
        }

        if ('' === $nick || '' === $rawLevel) {
            $this->reply->reply($uid, 'Error de Sintaxis: ACCESS <canal> ADD <nick> <nivel>');

            return;
        }

        if (1 !== preg_match('/^-?\d+$/', $rawLevel)) {
            $this->reply->reply($uid, 'El nivel del registro debe de ser un número.');

            return;
        }

        $level = (int) $rawLevel;

        if ($level < -1 || $level > 499) {
            $this->reply->reply($uid, 'El nivel de registro debe estar comprendido entre -1 y 499.');

            return;
        }

        try {
            $target = (new Nick($nick))->value;
        } catch (InvalidArgumentException) {
            $this->reply->reply($uid, 'Ese nick no puede tener acceso.');

            return;
        }

        $this->access->add($channel->name->value, $target, $level);
        $this->projection->publishAccess($channel->name->value, $target);
        $this->reply->reply($uid, sprintf(
            '%s agregado a la lista de acceso de %s con nivel %d.',
            $target,
            $channel->name->value,
            $level,
        ));
    }

    private function onAccessDel(
        string $uid,
        Channel $channel,
        string $nick,
        ?string $account,
        NetworkState $state,
    ): void {
        if (!$this->requireLevel($uid, $channel, ChannelLevel::AccChange, $this->authority($uid, $account, $channel, $state))) {
            return;
        }

        if ('' === $nick) {
            $this->reply->reply($uid, 'Error de Sintaxis: ACCESS <canal> DEL <nick>');

            return;
        }

        if (!$this->access->remove($channel->name->value, $nick)) {
            $this->reply->reply($uid, sprintf('%s no fue encontrado en la lista de acceso de %s.', $nick, $channel->name->value));

            return;
        }

        $this->projection->removeAccess($channel->name->value, $nick);
        $this->reply->reply($uid, sprintf('%s borrado de la lista de acceso de %s.', $nick, $channel->name->value));
    }

    /**
     * `OP|DEOP|HALFOP|DEHALFOP|VOICE|DEVOICE <#channel> <nick>`, from
     * `chanserv.ohv`. Removing a rank also requires standing above its holder.
     *
     * @param list<string> $args
     */
    private function onRank(
        string $command,
        string $uid,
        ?string $account,
        array $args,
        NetworkState $state,
    ): void {
        $channel = (string) ($args[0] ?? '');
        $nick = (string) ($args[1] ?? '');

        if ('' === $channel || '' === $nick) {
            $this->reply->reply($uid, sprintf('Error de Sintaxis: %s <canal> <nick>', $command));

            return;
        }

        $registered = $this->registeredChannel($uid, $channel);

        if (null === $registered) {
            return;
        }

        $target = $state->uidOf($nick);

        // A nick we have never seen cannot be a mode target. Whether they are in
        // the channel is left to the IRCd: when it creates a channel from a UDB
        // profile it sends no join at all, so our membership view can be blind.
        if (null === $target) {
            $this->reply->reply($uid, sprintf('El usuario %s no está en el canal.', $nick));

            return;
        }

        [$modes, $level, $removes] = match ($command) {
            'OP' => ['+o', ChannelLevel::OpDeOp, false],
            'DEOP' => ['-o', ChannelLevel::OpDeOp, true],
            'HALFOP' => ['+h', ChannelLevel::HalfOpDeHalfOp, false],
            'DEHALFOP' => ['-h', ChannelLevel::HalfOpDeHalfOp, true],
            'VOICE' => ['+v', ChannelLevel::VoiceDeVoice, false],
            'DEVOICE' => ['-v', ChannelLevel::VoiceDeVoice, true],
            default => ['', ChannelLevel::OpDeOp, false],
        };

        $authority = $this->authority($uid, $account, $registered, $state);

        if (!$this->requireLevel($uid, $registered, $level, $authority)) {
            return;
        }

        // You cannot take a rank away from somebody who stands above you.
        if ($removes && $authority < $this->authority($target, $state->accountOf($target), $registered, $state)) {
            $this->reply->reply($uid, 'Permiso denegado.');

            return;
        }

        $this->actions->mode($registered->name->value, $modes, $target);
        $this->actions->notice($registered->name->value, sprintf(
            '%s hace %s a %s.',
            $state->nickOf($uid) ?? '',
            $command,
            $nick,
        ));
    }

    /** `INVITE <#channel>` invites the sender, the way the original does. */
    private function onInvite(string $uid, ?string $account, array $args, NetworkState $state): void
    {
        $channel = (string) ($args[0] ?? '');

        if ('' === $channel) {
            $this->reply->reply($uid, 'Error de Sintaxis: INVITE <canal>');

            return;
        }

        $registered = $this->registeredChannel($uid, $channel);

        if (null === $registered) {
            return;
        }

        if (!$this->requireLevel($uid, $registered, ChannelLevel::Invite, $this->authority($uid, $account, $registered, $state))) {
            return;
        }

        if ($state->isInChannel($registered->name->value, $uid)) {
            $this->reply->reply($uid, 'Ya estás dentro del canal.');

            return;
        }

        $this->actions->invite($registered->name->value, $uid);
        $this->actions->notice($registered->name->value, sprintf('%s se invita al canal.', $state->nickOf($uid) ?? ''));
    }

    /**
     * `UNBAN <#channel>` lifts the sender's own ban. The original sends
     * `MODE #channel -b <nick>`, which only matches a ban written as the bare
     * nick; a ban with a full mask needs the ban list, which we do not track yet.
     */
    private function onUnban(string $uid, ?string $account, array $args, NetworkState $state): void
    {
        $channel = (string) ($args[0] ?? '');

        if ('' === $channel) {
            $this->reply->reply($uid, 'Error de Sintaxis: UNBAN <canal>');

            return;
        }

        $registered = $this->registeredChannel($uid, $channel);

        if (null === $registered) {
            return;
        }

        if (!$this->requireLevel($uid, $registered, ChannelLevel::Unban, $this->authority($uid, $account, $registered, $state))) {
            return;
        }

        $nick = $state->nickOf($uid) ?? '';

        $this->actions->mode($registered->name->value, '-b', $nick);
        $this->reply->reply($uid, sprintf('Has sido desbaneado de %s.', $registered->name->value));
    }

    /** The channel exists, or the sender is told it does not. */
    private function registeredChannel(string $uid, string $channel): ?Channel
    {
        try {
            $registered = $this->channels->find(new ChannelName($channel));
        } catch (InvalidArgumentException) {
            $this->reply->reply($uid, 'Ese nombre de canal no es válido.');

            return null;
        }

        if (null === $registered) {
            $this->reply->reply($uid, sprintf('%s no está registrado.', $channel));
        }

        return $registered;
    }

    /**
     * The level the sender holds in the channel: 500 for an IRCop, the founder by
     * account, or a session identified with the channel password; otherwise
     * whatever the access list grants, defaulting to 0.
     */
    private function authority(string $uid, ?string $account, Channel $channel, NetworkState $state): int
    {
        if ($state->hasMode($uid, 'o')) {
            return 500;
        }

        if (null !== $account && $channel->isFoundedBy($account)) {
            return 500;
        }

        if ($this->isIdentifiedFounder($uid, $channel->name->value)) {
            return 500;
        }

        return null === $account ? 0 : ($this->access->levelOf($channel->name->value, $account) ?? 0);
    }

    private function requireLevel(string $uid, Channel $channel, ChannelLevel $key, int $authority): bool
    {
        if ($authority >= $this->levels->threshold($channel->name, $key)) {
            return true;
        }

        $this->reply->reply($uid, 'Permiso denegado.');

        return false;
    }

    /**
     * `chanserv.c.r` notices the entry message when somebody joins, replacing
     * `<nick>` with their nick. It needs no permission: it is a greeting.
     */
    /**
     * `LEVELS <#channel> LIST|SET|RESET`, the per-channel threshold table.
     *
     * @param list<string> $args
     */
    private function onLevels(string $uid, ?string $account, array $args): void
    {
        $channel = (string) ($args[0] ?? '');
        $action = strtoupper((string) ($args[1] ?? ''));

        if ('' === $channel || !in_array($action, ['LIST', 'SET', 'RESET'], true)) {
            $this->reply->reply($uid, 'Error de Sintaxis: LEVELS <canal> {LIST|SET|RESET} <clave> <nivel>');

            return;
        }

        $registered = $this->ownedChannel($uid, $account, $channel);

        if (null === $registered) {
            return;
        }

        match ($action) {
            'LIST' => $this->listLevels($uid, $registered->name),
            'SET' => $this->setLevel(
                $uid,
                $registered->name,
                strtoupper((string) ($args[2] ?? '')),
                (string) ($args[3] ?? ''),
            ),
            'RESET' => $this->resetLevels($uid, $registered->name),
        };
    }

    private function listLevels(string $uid, ChannelName $channel): void
    {
        $this->reply->reply($uid, sprintf('Configuración de niveles de acceso para el canal %s:', $channel->value));

        foreach ($this->levels->levelsFor($channel) as $name => $threshold) {
            $this->reply->reply($uid, sprintf('    %-18s %d', $name, $threshold));
        }

        $this->reply->reply($uid, 'Fin de la configuración.');
    }

    private function setLevel(string $uid, ChannelName $channel, string $name, string $value): void
    {
        $level = ChannelLevel::fromName($name);

        if (null === $level) {
            $this->reply->reply($uid, sprintf('Opción %s desconocida.', $name));

            return;
        }

        if (1 !== preg_match('/^-?\d+$/', $value)) {
            $this->reply->reply($uid, 'Error de Sintaxis: LEVELS <canal> SET <clave> <nivel>');

            return;
        }

        $threshold = (int) $value;

        if ($threshold < -1 || $threshold > 500) {
            $this->reply->reply($uid, 'El Nivel debe de estar entre -1 y 500.');

            return;
        }

        $this->levels->set($channel, $level, $threshold);
        $this->reply->reply($uid, sprintf(
            'El nivel para %s en canal %s cambiado a %d.',
            $level->value,
            $channel->value,
            $threshold,
        ));
    }

    private function resetLevels(string $uid, ChannelName $channel): void
    {
        $this->levels->reset($channel);
        $this->reply->reply($uid, sprintf('Niveles de acceso de %s configurados a niveles originales.', $channel->value));
    }

    /**
     * `SET <#channel> <option> [value]`, the subset of `chanserv.set` that does
     * not depend on the level table yet.
     *
     * @param list<string> $args
     */
    private function onSet(string $uid, ?string $account, array $args): void
    {
        $channel = (string) ($args[0] ?? '');
        $option = strtoupper((string) ($args[1] ?? ''));
        $value = implode(' ', array_slice($args, 2));

        if ('' === $channel || '' === $option) {
            $this->reply->reply($uid, 'Sintaxis: SET <#canal> <opción> [valor]');

            return;
        }

        $registered = $this->ownedChannel($uid, $account, $channel);

        if (null === $registered) {
            return;
        }

        match ($option) {
            'DESC', 'DESCRIPTION' => $this->setDescription($uid, $registered->name, $value),
            'URL' => $this->setUrl($uid, $registered->name, $value),
            'EMAIL' => $this->setEmail($uid, $registered->name, $value),
            'ENTRYMSG' => $this->setEntryMessage($uid, $registered->name, $value),
            'PASSWORD' => $this->setPassword($uid, $registered->name, $value),
            default => $this->reply->reply($uid, sprintf('Opción desconocida %s. Usa AYUDA SET.', $option)),
        };
    }

    private function setDescription(string $uid, ChannelName $channel, string $value): void
    {
        if ('' === $value) {
            $this->reply->reply($uid, 'Error de Sintaxis: SET <canal> DESC <texto>');

            return;
        }

        $this->channels->updateSetting($channel, ChannelSetting::Description, $value);
        $this->reply->reply($uid, sprintf('La Descripción de %s ha sido cambiada a %s.', $channel->value, $value));
    }

    private function setUrl(string $uid, ChannelName $channel, string $value): void
    {
        if ('' === $value) {
            $this->channels->updateSetting($channel, ChannelSetting::Url, null);
            $this->reply->reply($uid, sprintf('URL para %s deshabilitada.', $channel->value));

            return;
        }

        $this->channels->updateSetting($channel, ChannelSetting::Url, $value);
        $this->reply->reply($uid, sprintf('URL para %s cambiada a %s.', $channel->value, $value));
    }

    private function setEmail(string $uid, ChannelName $channel, string $value): void
    {
        if ('' === $value) {
            $this->channels->updateSetting($channel, ChannelSetting::Email, null);
            $this->reply->reply($uid, sprintf('Dirección de E-Mail para %s deshabilitada.', $channel->value));

            return;
        }

        $this->channels->updateSetting($channel, ChannelSetting::Email, $value);
        $this->reply->reply($uid, sprintf('Dirección de E-Mail para %s cambiada a %s.', $channel->value, $value));
    }

    private function setEntryMessage(string $uid, ChannelName $channel, string $value): void
    {
        if ('' === $value) {
            $this->channels->updateSetting($channel, ChannelSetting::EntryMessage, null);
            $this->reply->reply($uid, sprintf('Mensaje de entrada para %s deshabilitado.', $channel->value));

            return;
        }

        $this->channels->updateSetting($channel, ChannelSetting::EntryMessage, $value);
        $this->reply->reply($uid, sprintf('Mensaje de entrada para %s cambiado.', $channel->value));
    }

    /**
     * The original answers with the new password in clear. We do not: a NOTICE is
     * logged by clients, so the reply only confirms the change.
     */
    private function setPassword(string $uid, ChannelName $channel, string $value): void
    {
        if ('' === $value) {
            $this->reply->reply($uid, 'Error de Sintaxis: SET <canal> PASSWORD <contraseña>');

            return;
        }

        try {
            $secret = Password::fromPlain($value);
        } catch (InvalidArgumentException) {
            $this->reply->reply($uid, 'La contraseña debe tener al menos 6 caracteres.');

            return;
        }

        $this->channels->updateSetting($channel, ChannelSetting::Password, $secret->hash());
        $this->reply->reply($uid, sprintf('Contraseña de %s cambiada.', $channel->value));
    }

    /** @param list<string> $args */
    private function onTopic(string $uid, ?string $account, array $args): void
    {
        $channel = (string) ($args[0] ?? '');

        if ('' === $channel) {
            $this->reply->reply($uid, 'Syntax: TOPIC <#channel> <text>');

            return;
        }

        $registered = $this->ownedChannel($uid, $account, $channel);

        if (null === $registered) {
            return;
        }

        $topic = implode(' ', array_slice($args, 1));
        $this->channels->updateTopic(new Channel($registered->name, $registered->founder, $registered->createdAt, $topic));
        $this->projection->publishTopic($registered->name->value);

        $this->reply->reply($uid, sprintf('Topic for %s updated.', $registered->name->value));
    }

    /** Returns the channel only when the sender is its founder. */
    private function ownedChannel(string $uid, ?string $account, string $channel): ?Channel
    {
        try {
            $registered = $this->channels->find(new ChannelName($channel));
        } catch (InvalidArgumentException) {
            $this->reply->reply($uid, 'That channel name cannot be registered.');

            return null;
        }

        if (null === $registered) {
            $this->reply->reply($uid, sprintf('%s is not registered.', $channel));

            return null;
        }

        $isFounder = null !== $account && $registered->isFoundedBy($account);

        if (!$isFounder && !$this->isIdentifiedFounder($uid, $registered->name->value)) {
            $this->reply->reply($uid, 'Solo el fundador puede hacer eso.');

            return null;
        }

        return $registered;
    }

    /** @param list<string> $args */
    private function onHelp(string $uid, array $args, NetworkState $state): void
    {
        $isOper = $state->hasMode($uid, 'o');

        foreach ($this->help->render(implode(' ', $args), $isOper) as $line) {
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
