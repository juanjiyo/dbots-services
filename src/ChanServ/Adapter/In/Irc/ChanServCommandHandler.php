<?php

declare(strict_types=1);

namespace Dbots\ChanServ\Adapter\In\Irc;

use Dbots\ChanServ\Application\DropChannel\DropChannelHandler;
use Dbots\ChanServ\Application\DropChannel\DropChannelResult;
use Dbots\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use Dbots\ChanServ\Application\Port\Out\ChannelActionsInterface;
use Dbots\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use Dbots\ChanServ\Application\Port\Out\ChannelForbidRepositoryInterface;
use Dbots\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use Dbots\ChanServ\Application\Port\Out\ChannelProjectionInterface;
use Dbots\ChanServ\Application\Port\Out\ChannelRepositoryInterface;
use Dbots\Domain\Account\Nick;
use Dbots\Domain\Account\Password;
use Dbots\Domain\Channel\Akick;
use Dbots\Domain\Channel\Channel;
use Dbots\Domain\Channel\ChannelLevel;
use Dbots\Domain\Channel\ChannelName;
use Dbots\Domain\Channel\ChannelSetting;
use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\Help\HelpCatalog;
use Dbots\Irc\Application\NetworkState;
use Dbots\Irc\Application\AccountResolver;
use Dbots\NickServ\Application\Port\Out\AccountRepositoryInterface;
use Dbots\NickServ\Application\Port\Out\ReplyInterface;
use DateTimeImmutable;
use InvalidArgumentException;

use function array_filter;
use function array_shift;
use function array_values;
use function explode;
use function array_slice;
use function implode;
use function in_array;
use function str_starts_with;
use function preg_match;
use function preg_quote;
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
        private ChannelAkickRepositoryInterface $akicks,
        private ChannelForbidRepositoryInterface $forbids,
        private AccountRepositoryInterface $accountStore,
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
            'CLEAR' => $this->onClear($sender, $account, $parts, $state),
            'AKICK' => $this->onAkick($sender, $account, $parts, $state),
            'SUSPEND' => $this->onSuspend($sender, $parts, $state),
            'UNSUSPEND' => $this->onUnsuspend($sender, $parts, $state),
            'LISTSUSPEND' => $this->onListSuspend($sender, $state),
            'FORBID' => $this->onForbid($sender, $parts, $state),
            'UNFORBID' => $this->onUnforbid($sender, $parts, $state),
            'LISTFORBID' => $this->onListForbid($sender, $state),
            'LIST' => $this->onList($sender, $parts, $state),
            'GETPASS' => $this->onGetpass($sender, $parts, $state),
            'BFOUNDER' => $this->onBfounder($sender, $parts, $state),
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

    /**
     * `CLEAR <#channel> <option>`, from `chanserv.clear`.
     *
     * @param list<string> $args
     */
    private function onClear(string $uid, ?string $account, array $args, NetworkState $state): void
    {
        $channel = (string) ($args[0] ?? '');
        $option = strtoupper((string) ($args[1] ?? ''));

        if ('' === $channel || '' === $option) {
            $this->reply->reply($uid, 'Error de Sintaxis: CLEAR <canal> <opción>');

            return;
        }

        $registered = $this->registeredChannel($uid, $channel);

        if (null === $registered) {
            return;
        }

        if (!$this->requireLevel($uid, $registered, ChannelLevel::Clear, $this->authority($uid, $account, $registered, $state))) {
            return;
        }

        $name = $registered->name->value;

        match ($option) {
            'MODES' => $this->clearModes($uid, $name),
            'OPS' => $this->clearRank($uid, $name, 'o', $state),
            'HALFOPS' => $this->clearRank($uid, $name, 'h', $state),
            'VOICES' => $this->clearRank($uid, $name, 'v', $state),
            'BANS' => $this->clearBans($uid, $name, $state),
            'USERS' => $this->clearUsers($uid, $name, $state),
            'TOPIC' => $this->restoreTopic($uid, $registered),
            default => $this->reply->reply($uid, 'Error de Sintaxis: CLEAR <canal> <opción>'),
        };
    }

    private function clearModes(string $uid, string $channel): void
    {
        // The original wipes the same set in one MODE line.
        $this->actions->channelModes($channel, '-ntimlpsRMG');
        $this->reply->reply($uid, sprintf('Todos los modos en canal %s han sido borrados.', $channel));
    }

    private function clearRank(string $uid, string $channel, string $rank, NetworkState $state): void
    {
        foreach ($state->membersWithRank($channel, $rank) as $member) {
            $this->actions->mode($channel, sprintf('-%s', $rank), $member);
        }

        $this->reply->reply($uid, sprintf('Modo +%s de operadores han sido desactivados del canal %s.', $rank, $channel));
    }

    private function clearBans(string $uid, string $channel, NetworkState $state): void
    {
        $masks = $state->bansOf($channel);

        if ([] === $masks) {
            $this->reply->reply($uid, sprintf('El canal %s no tiene bans que borrar.', $channel));

            return;
        }

        foreach ($masks as $mask) {
            $this->actions->mode($channel, '-b', $mask);
        }

        $this->reply->reply($uid, sprintf('Todos los bans en canal %s han sido borrados.', $channel));
    }

    private function clearUsers(string $uid, string $channel, NetworkState $state): void
    {
        foreach ($state->membersOf($channel) as $member) {
            $this->actions->kick($channel, $member, 'CLEAR USERS');
        }

        $this->reply->reply($uid, sprintf('Todos los usuarios han sido expulsados del canal %s.', $channel));
    }

    private function restoreTopic(string $uid, Channel $channel): void
    {
        $topic = $channel->topic ?? '';
        $this->actions->setTopic($channel->name->value, $topic);
        $this->reply->reply($uid, sprintf('Tópico de %s restaurado.', $channel->name->value));
    }

    /** @param list<string> $args */
    private function onAkick(string $uid, ?string $account, array $args, NetworkState $state): void
    {
        $channel = (string) ($args[0] ?? '');
        $action = strtoupper((string) ($args[1] ?? ''));
        $nick = (string) ($args[2] ?? '');
        $reason = implode(' ', array_slice($args, 3));

        if ('' === $channel || !in_array($action, ['ADD', 'DEL', 'LIST', 'ENFORCE'], true)) {
            $this->reply->reply($uid, 'Error de Sintaxis: AKICK <canal> {ADD|DEL|LIST|ENFORCE} <nick> <motivo>');

            return;
        }

        $registered = $this->registeredChannel($uid, $channel);

        if (null === $registered) {
            return;
        }

        $key = 'LIST' === $action ? ChannelLevel::AkickList : ChannelLevel::AkickChange;

        if (!$this->requireLevel($uid, $registered, $key, $this->authority($uid, $account, $registered, $state))) {
            return;
        }

        match ($action) {
            'ADD' => $this->akickAdd($uid, $registered, $nick, $reason, $account, $state),
            'DEL' => $this->akickDel($uid, $registered, $nick),
            'LIST' => $this->akickList($uid, $registered),
            'ENFORCE' => $this->akickEnforce($uid, $registered, $state),
        };
    }

    private function akickAdd(string $uid, Channel $channel, string $nick, string $reason, ?string $account, NetworkState $state): void
    {
        if ('' === $nick) {
            $this->reply->reply($uid, 'Error de Sintaxis: AKICK <canal> ADD <nick> <motivo>');

            return;
        }

        try {
            $target = new Nick($nick);
        } catch (InvalidArgumentException) {
            $this->reply->reply($uid, 'Ese nick no es válido.');

            return;
        }

        if (null === $this->accountStore->findByNick($target)) {
            $this->reply->reply($uid, sprintf('El nick %s no está registrado.', $target->value));

            return;
        }

        if ($this->akicks->has($channel->name->value, $target->value)) {
            $this->reply->reply($uid, sprintf('%s ya existe en la lista de autokicks de %s.', $target->value, $channel->name->value));

            return;
        }

        if ($this->akicks->countFor($channel->name->value) >= 50) {
            $this->reply->reply($uid, 'Superado el número máximo de akicks del canal.');

            return;
        }

        $setBy = $state->nickOf($uid) ?? ($account ?? '');

        $this->akicks->add($channel->name->value, new Akick($target->value, '' === $reason ? 'Sin motivo' : $reason, $setBy));
        $this->reply->reply($uid, sprintf('%s agregado a la lista de autokick de %s.', $target->value, $channel->name->value));
    }

    private function akickDel(string $uid, Channel $channel, string $nick): void
    {
        if ('' === $nick) {
            $this->reply->reply($uid, 'Error de Sintaxis: AKICK <canal> DEL <nick>');

            return;
        }

        if (!$this->akicks->remove($channel->name->value, $nick)) {
            $this->reply->reply($uid, sprintf('%s no existe en la lista de autokicks de %s.', $nick, $channel->name->value));

            return;
        }

        $this->reply->reply($uid, sprintf('%s borrado de la lista de autokick de %s.', $nick, $channel->name->value));
    }

    private function akickList(string $uid, Channel $channel): void
    {
        $entries = $this->akicks->forChannel($channel->name->value);

        $this->reply->reply($uid, sprintf('Lista de autokick de %s:', $channel->name->value));

        if ([] === $entries) {
            $this->reply->reply($uid, 'Lista vacía.');

            return;
        }

        foreach ($entries as $akick) {
            $this->reply->reply($uid, sprintf('Nick: %s - Motivo: (%s) -> %s', $akick->nick, $akick->setBy, $akick->reason));
        }

        $this->reply->reply($uid, 'Fin de la lista.');
    }

    private function akickEnforce(string $uid, Channel $channel, NetworkState $state): void
    {
        foreach ($this->akicks->forChannel($channel->name->value) as $akick) {
            $member = $state->uidOf($akick->nick);

            if (null === $member || !$state->isInChannel($channel->name->value, $member)) {
                continue;
            }

            $this->actions->kick($channel->name->value, $member, sprintf('(%s) -> %s', $akick->setBy, $akick->reason));
        }

        $this->reply->reply($uid, 'Han sido expulsados del canal los nicks con akick.');
    }

    /** @param list<string> $args */
    private function onSuspend(string $uid, array $args, NetworkState $state): void
    {
        $channel = (string) ($args[0] ?? '');
        $reason = implode(' ', array_slice($args, 1));

        if ('' === $channel || '' === $reason) {
            $this->reply->reply($uid, 'Error de Sintaxis: SUSPEND <canal> <motivo>');

            return;
        }

        if (!$this->isOper($uid, $state)) {
            $this->reply->reply($uid, 'Permiso denegado.');

            return;
        }

        $registered = $this->registeredChannel($uid, $channel);

        if (null === $registered) {
            return;
        }

        if ($registered->isSuspended()) {
            $this->reply->reply($uid, sprintf('El canal %s ya está suspendido.', $registered->name->value));

            return;
        }

        $this->channels->updateSuspension(new Channel(
            $registered->name,
            $registered->founder,
            $registered->createdAt,
            $registered->topic,
            $registered->passwordHash,
            $registered->description,
            $registered->url,
            $registered->email,
            $registered->entryMessage,
            $reason,
            $state->nickOf($uid),
            new DateTimeImmutable(),
        ));
        $this->projection->publishSuspension($registered->name->value);

        $this->reply->reply($uid, sprintf('El canal %s ha sido suspendido.', $registered->name->value));
    }

    /** @param list<string> $args */
    private function onUnsuspend(string $uid, array $args, NetworkState $state): void
    {
        $channel = (string) ($args[0] ?? '');

        if ('' === $channel) {
            $this->reply->reply($uid, 'Error de Sintaxis: UNSUSPEND <canal>');

            return;
        }

        if (!$this->isOper($uid, $state)) {
            $this->reply->reply($uid, 'Permiso denegado.');

            return;
        }

        $registered = $this->registeredChannel($uid, $channel);

        if (null === $registered) {
            return;
        }

        if (!$registered->isSuspended()) {
            $this->reply->reply($uid, sprintf('El canal %s no está suspendido.', $registered->name->value));

            return;
        }

        $this->channels->updateSuspension(new Channel(
            $registered->name,
            $registered->founder,
            $registered->createdAt,
            $registered->topic,
            $registered->passwordHash,
            $registered->description,
            $registered->url,
            $registered->email,
            $registered->entryMessage,
        ));
        $this->projection->publishSuspension($registered->name->value);

        $this->reply->reply($uid, sprintf('El canal %s ha sido liberado.', $registered->name->value));
    }

    private function onListSuspend(string $uid, NetworkState $state): void
    {
        if (!$this->isOper($uid, $state)) {
            $this->reply->reply($uid, 'Permiso denegado.');

            return;
        }

        $suspended = array_values(array_filter(
            $this->channels->all(),
            static fn (Channel $channel): bool => $channel->isSuspended(),
        ));

        $this->reply->reply($uid, 'Listado de canales suspendidos');

        if ([] === $suspended) {
            $this->reply->reply($uid, 'Lista vacía.');

            return;
        }

        foreach ($suspended as $channel) {
            $this->reply->reply($uid, sprintf('%s - Motivo: %s', $channel->name->value, (string) $channel->suspendReason));
        }
    }

    private function isOper(string $uid, NetworkState $state): bool
    {
        return $state->hasMode($uid, 'o');
    }

    /** @param list<string> $args */
    private function onForbid(string $uid, array $args, NetworkState $state): void
    {
        $channel = (string) ($args[0] ?? '');
        $reason = implode(' ', array_slice($args, 1));

        if ('' === $channel || '' === $reason) {
            $this->reply->reply($uid, 'Error de Sintaxis: FORBID <canal> <motivo>');

            return;
        }

        if (!$this->isOper($uid, $state)) {
            $this->reply->reply($uid, 'Permiso denegado.');

            return;
        }

        if (!str_starts_with($channel, '#')) {
            $this->reply->reply($uid, 'El canal debe comenzar por #');

            return;
        }

        $name = strtolower($channel);

        if (null !== $this->forbids->find($name)) {
            $this->reply->reply($uid, sprintf('El canal %s ya está en la lista de forbid.', $channel));

            return;
        }

        // A forbidden channel cannot stay registered: the profile goes first.
        try {
            $registered = $this->channels->find(new ChannelName($name));
        } catch (InvalidArgumentException) {
            $registered = null;
        }

        if (null !== $registered) {
            $this->channels->delete($registered->name);
            $this->access->removeAll($registered->name->value);
            $this->akicks->removeAll($registered->name->value);
            $this->levels->forget($registered->name);
            $this->projection->publishDropped($registered->name->value);
        }

        $this->forbids->add($name, $reason, $state->nickOf($uid) ?? '');
        $this->projection->publishForbid($name, $reason);

        $this->reply->reply($uid, sprintf('El canal %s ha sido prohibido.', $channel));
    }

    /** @param list<string> $args */
    private function onUnforbid(string $uid, array $args, NetworkState $state): void
    {
        $channel = (string) ($args[0] ?? '');

        if ('' === $channel) {
            $this->reply->reply($uid, 'Error de Sintaxis: UNFORBID <canal>');

            return;
        }

        if (!$this->isOper($uid, $state)) {
            $this->reply->reply($uid, 'Permiso denegado.');

            return;
        }

        if (!$this->forbids->remove(strtolower($channel))) {
            $this->reply->reply($uid, sprintf('El canal %s no está en la lista de forbid.', $channel));

            return;
        }

        $this->projection->publishForbid($channel, null);
        $this->reply->reply($uid, sprintf('El canal %s ha sido liberado.', $channel));
    }

    private function onListForbid(string $uid, NetworkState $state): void
    {
        if (!$this->isOper($uid, $state)) {
            $this->reply->reply($uid, 'Permiso denegado.');

            return;
        }

        $forbids = $this->forbids->all();

        if ([] === $forbids) {
            $this->reply->reply($uid, 'Lista vacía.');

            return;
        }

        $this->reply->reply($uid, sprintf('Listado de canales prohibidos -> %d', count($forbids)));

        foreach ($forbids as $channel => $forbid) {
            $this->reply->reply($uid, sprintf('%s (%s) %s', $channel, $forbid['setBy'], $forbid['reason']));
        }

        $this->reply->reply($uid, 'Fin de la lista de canales prohibidos.');
    }

    /** @param list<string> $args */
    private function onList(string $uid, array $args, NetworkState $state): void
    {
        $pattern = (string) ($args[0] ?? '');

        if ('' === $pattern) {
            $this->reply->reply($uid, 'Error de Sintaxis: LIST <patrón>');

            return;
        }

        if (!$this->isOper($uid, $state)) {
            $this->reply->reply($uid, 'Permiso denegado.');

            return;
        }

        $everything = $this->channels->all();
        $shown = 0;

        $this->reply->reply($uid, sprintf('Lista de Canales que satisfacen %s:', $pattern));

        foreach ($everything as $channel) {
            if (50 === $shown) {
                break;
            }

            if (1 !== preg_match('/'.str_replace('\*', '.*', preg_quote($pattern, '/')).'/i', $channel->name->value)) {
                continue;
            }

            $this->reply->reply($uid, sprintf('%s - %s (BDD)', $channel->name->value, $channel->isSuspended() ? 'SUSPENDIDO' : 'ACTIVO'));
            ++$shown;
        }

        $this->reply->reply($uid, sprintf('Fin de la lista, %d/%d entradas mostradas.', $shown, count($everything)));
    }

    /** @param list<string> $args */
    private function onGetpass(string $uid, array $args, NetworkState $state): void
    {
        $channel = (string) ($args[0] ?? '');

        if ('' === $channel) {
            $this->reply->reply($uid, 'Error de Sintaxis: GETPASS <canal>');

            return;
        }

        if (!$this->isOper($uid, $state)) {
            $this->reply->reply($uid, 'Permiso denegado.');

            return;
        }

        $registered = $this->registeredChannel($uid, $channel);

        if (null === $registered) {
            return;
        }

        // The original answers with the password in clear. We only keep a hash,
        // so the honest answer is that it cannot be recovered.
        $this->reply->reply($uid, sprintf(
            'La contraseña de %s está cifrada y no se puede recuperar. Usa SET PASSWORD para cambiarla.',
            $registered->name->value,
        ));
    }

    /** @param list<string> $args */
    private function onBfounder(string $uid, array $args, NetworkState $state): void
    {
        $channel = (string) ($args[0] ?? '');

        if ('' === $channel) {
            $this->reply->reply($uid, 'Error de Sintaxis: BFOUNDER <canal>');

            return;
        }

        if (!$this->isOper($uid, $state)) {
            $this->reply->reply($uid, 'Permiso denegado.');

            return;
        }

        try {
            $registered = $this->channels->find(new ChannelName($channel));
        } catch (InvalidArgumentException) {
            $registered = null;
        }

        if (null === $registered) {
            $this->reply->reply($uid, sprintf('No hay founder para el canal %s en el UDB.', $channel));

            return;
        }

        // Our profile is the source, so removing the founder means removing it.
        $this->channels->delete($registered->name);
        $this->access->removeAll($registered->name->value);
        $this->akicks->removeAll($registered->name->value);
        $this->levels->forget($registered->name);
        $this->projection->publishFounderRemoved($registered->name->value);

        $this->reply->reply($uid, sprintf('Borrada la entrada FOUNDER del canal %s en el UDB.', $registered->name->value));
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
        if ($channel->isSuspended()) {
            $this->reply->reply($uid, sprintf('El canal %s está suspendido.', $channel->name->value));

            return false;
        }

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

        if ($registered->isSuspended()) {
            $this->reply->reply($uid, sprintf('El canal %s está suspendido.', $registered->name->value));

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
