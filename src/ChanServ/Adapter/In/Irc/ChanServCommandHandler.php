<?php

declare(strict_types=1);

namespace Dbots\ChanServ\Adapter\In\Irc;

use Dbots\ChanServ\Application\DropChannel\DropChannelHandler;
use Dbots\ChanServ\Application\DropChannel\DropChannelResult;
use Dbots\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use Dbots\ChanServ\Application\Port\Out\ChannelProjectionInterface;
use Dbots\ChanServ\Application\Port\Out\ChannelRepositoryInterface;
use Dbots\Domain\Account\Nick;
use Dbots\Domain\Account\Password;
use Dbots\Domain\Channel\Channel;
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
            'ACCESS' => $this->onAccess($sender, $account, $parts),
            'TOPIC' => $this->onTopic($sender, $account, $parts),
            'SET' => $this->onSet($sender, $account, $parts),
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
    private function onAccess(string $uid, ?string $account, array $args): void
    {
        $action = strtoupper((string) ($args[0] ?? ''));
        $channel = (string) ($args[1] ?? '');
        $nick = (string) ($args[2] ?? '');

        if ('' === $channel) {
            $this->reply->reply($uid, 'Syntax: ACCESS LIST|ADD|DEL <#channel> [nick]');

            return;
        }

        $registered = $this->ownedChannel($uid, $account, $channel);

        if (null === $registered) {
            return;
        }

        match ($action) {
            'LIST' => $this->onAccessList($uid, $registered),
            'ADD' => $this->onAccessAdd($uid, $registered, $nick),
            'DEL', 'DELETE' => $this->onAccessDel($uid, $registered, $nick),
            default => $this->reply->reply($uid, 'Syntax: ACCESS LIST|ADD|DEL <#channel> [nick]'),
        };
    }

    private function onAccessList(string $uid, Channel $channel): void
    {
        $entries = $this->access->forChannel($channel->name->value);

        if ([] === $entries) {
            $this->reply->reply($uid, sprintf('%s has no access list entries.', $channel->name->value));

            return;
        }

        $this->reply->reply($uid, sprintf('Access list for %s: %s.', $channel->name->value, implode(', ', $entries)));
    }

    private function onAccessAdd(string $uid, Channel $channel, string $nick): void
    {
        if ('' === $nick) {
            $this->reply->reply($uid, 'Syntax: ACCESS ADD <#channel> <nick>');

            return;
        }

        try {
            $account = (new Nick($nick))->value;
        } catch (InvalidArgumentException) {
            $this->reply->reply($uid, 'That nickname cannot hold access.');

            return;
        }

        $this->access->add($channel->name->value, $account);
        $this->projection->publishAccess($channel->name->value, $account);
        $this->reply->reply($uid, sprintf('%s now has access to %s.', $account, $channel->name->value));
    }

    private function onAccessDel(string $uid, Channel $channel, string $nick): void
    {
        if ('' === $nick) {
            $this->reply->reply($uid, 'Syntax: ACCESS DEL <#channel> <nick>');

            return;
        }

        $removed = $this->access->remove($channel->name->value, $nick);

        if ($removed) {
            $this->projection->removeAccess($channel->name->value, $nick);
        }

        $this->reply->reply($uid, $removed
            ? sprintf('%s no longer has access to %s.', $nick, $channel->name->value)
            : sprintf('%s is not on the access list of %s.', $nick, $channel->name->value));
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
