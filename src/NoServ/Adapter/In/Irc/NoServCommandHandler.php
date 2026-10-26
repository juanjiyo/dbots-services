<?php

declare(strict_types=1);

namespace Dbots\NoServ\Adapter\In\Irc;

use Dbots\Application\Port\Out\LinkTransportInterface;
use Dbots\Domain\Account\Nick;
use Dbots\Domain\Staff\StaffRank;
use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Adapter\Out\Irc\ServiceUidFormatter;
use Dbots\Irc\Application\Help\HelpCatalog;
use Dbots\Irc\Application\NetworkState;
use Dbots\NickServ\Application\Port\Out\AccountRepositoryInterface;
use Dbots\NickServ\Application\Port\Out\EmailSenderInterface;
use Dbots\NickServ\Application\Port\Out\ReplyInterface;
use Dbots\NoServ\Application\Port\Out\MotdNickRepositoryInterface;
use Dbots\NoServ\Application\Port\Out\NewsMessageRepositoryInterface;
use Dbots\NoServ\Application\Port\Out\NewsSubscriptionRepositoryInterface;
use Dbots\NoServ\Application\Port\Out\ServerEntryMessageRepositoryInterface;
use Dbots\Staff\Application\StaffAuthority;
use InvalidArgumentException;

use function array_filter;
use function array_shift;
use function array_values;
use function explode;
use function file_get_contents;
use function implode;
use function is_numeric;
use function md5;
use function sprintf;
use function str_contains;
use function strlen;
use function strtoupper;
use function substr;
use function trim;

final readonly class NoServCommandHandler
{
    private string $sid;
    private ServiceUidFormatter $uidFormatter;

    /** @param list<string> $reservedNicks */
    public function __construct(
        private ReplyInterface $reply,
        private LinkTransportInterface $transport,
        private StaffAuthority $staff,
        private AccountRepositoryInterface $accounts,
        private NewsSubscriptionRepositoryInterface $subscriptions,
        private NewsMessageRepositoryInterface $motdMessages,
        private NewsMessageRepositoryInterface $representantesMessages,
        private ServerEntryMessageRepositoryInterface $serverMessages,
        private MotdNickRepositoryInterface $motdNicks,
        private EmailSenderInterface $emailSender,
        private string $serviceNick,
        private string $serviceUid,
        private HelpCatalog $help,
        private array $reservedNicks = [],
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
            $this->onHelp($sender, [], $state);

            return;
        }

        $command = strtoupper((string) array_shift($parts));

        match ($command) {
            'ALTA' => $this->onAlta($sender, $state),
            'BAJA' => $this->onBaja($sender, $state),
            'EMAIL' => $this->onEmail($sender, $parts, $state),
            'MOTD' => $this->onMotd($sender, $parts, $state),
            'NICK' => $this->onNick($sender, $parts, $state),
            'REPRESENTANTES' => $this->onRepresentantes($sender, $parts, $state),
            'NOTICIAS' => $this->onNoticias($sender, $state),
            'SERVIDOR' => $this->onServidor($sender, $parts, $state),
            'HELP', 'AYUDA' => $this->onHelp($sender, $parts, $state),
            default => $this->reply->reply($sender, sprintf(
                'Comando desconocido %s. /msg %s AYUDA para ayuda.',
                $command,
                $this->serviceNick,
            )),
        };
    }

    private function onAlta(string $sender, NetworkState $state): void
    {
        if (!$this->requireIdentified($sender, $state)) {
            return;
        }

        $account = $state->accountOf($sender);

        if ($this->subscriptions->isSubscribed((string) $account)) {
            $this->reply->reply($sender, 'Tu nick ya está dado de alta en el servicio.');

            return;
        }

        $this->subscriptions->subscribe((string) $account);
        $this->reply->reply($sender, 'Tu nick acaba de ser dado de alta en el servicio de noticias.');
    }

    private function onBaja(string $sender, NetworkState $state): void
    {
        if (!$this->requireIdentified($sender, $state)) {
            return;
        }

        $account = $state->accountOf($sender);

        if (!$this->subscriptions->isSubscribed((string) $account)) {
            $this->reply->reply($sender, 'Tu nick no está dado de alta en el servicio.');

            return;
        }

        $this->subscriptions->unsubscribe((string) $account);
        $this->reply->reply($sender, 'Tu nick acaba de ser dado de baja en el servicio de noticias.');
    }

    /** @param list<string> $args */
    private function onEmail(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireAdmin($sender, $state)) {
            return;
        }

        $text = implode(' ', $args);

        if ('' === $text) {
            $this->reply->reply($sender, 'Error de Sintaxis: EMAIL <mensaje>');

            return;
        }

        foreach ($this->subscriptions->allSubscribers() as $account) {
            $this->sendEmailTo($account, $text);
        }

        $this->reply->reply($sender, 'E-mail enviado a todos los usuarios dados de alta en el servicio de noticias.');
    }

    /** @param list<string> $args */
    private function onMotd(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireAdmin($sender, $state)) {
            return;
        }

        $this->manageNewsList(
            $sender,
            $args,
            $state,
            $this->motdMessages,
            "\x033[ Noticias de la Red ]\x03",
            'usuarios',
        );
    }

    /** @param list<string> $args */
    private function onRepresentantes(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireAdmin($sender, $state)) {
            return;
        }

        $this->manageNewsList(
            $sender,
            $args,
            $state,
            $this->representantesMessages,
            "\x033[ Noticias para Representantes ]\x03",
            'representantes',
            true,
        );
    }

    /**
     * @param list<string>                   $args
     * @param NewsMessageRepositoryInterface $repository
     */
    private function manageNewsList(
        string $sender,
        array $args,
        NetworkState $state,
        NewsMessageRepositoryInterface $repository,
        string $header,
        string $label,
        bool $staffOnly = false,
    ): void {
        $subcommand = array_shift($args);

        if (null === $subcommand) {
            $this->reply->reply($sender, sprintf('Error de Sintaxis: %s ADD|DEL|LIST [mensaje]', strtoupper($label)));

            return;
        }

        $subcommand = strtoupper($subcommand);

        if ('ADD' === $subcommand) {
            $text = implode(' ', $args);

            if ('' === $text) {
                $this->reply->reply($sender, sprintf('ERROR: %s add mensaje.', strtolower($label)));

                return;
            }

            $repository->add($state->nickOf($sender) ?? $sender, $text);

            $recipients = $staffOnly
                ? $this->staffRecipients($state, StaffRank::PreOperador)
                : $state->users();

            $this->broadcast($recipients, $text, $header);
            $this->reply->reply($sender, 'Mensaje añadido.');

            return;
        }

        if ('DEL' === $subcommand) {
            $position = array_shift($args);

            if (null === $position || !is_numeric($position) || str_contains($position, '.')) {
                $this->reply->reply($sender, sprintf('Sintaxis incorrecta: %s del numero.', strtolower($label)));

                return;
            }

            $position = (int) $position;
            $items = $repository->all();

            if (!isset($items[$position - 1])) {
                $this->reply->reply($sender, sprintf('ERROR: No se ha encontrado ninguna noticia en la posición %d.', $position));

                return;
            }

            $item = $items[$position - 1];
            $repository->remove($position);
            $this->reply->reply($sender, sprintf(
                'Borrada la noticia numero %d añadida por %s el %s: %s',
                $position,
                $item['author'],
                $item['date'],
                $item['text'],
            ));

            return;
        }

        if ('LIST' === $subcommand) {
            $items = $repository->all();

            if ([] === $items) {
                $this->reply->reply($sender, sprintf('Lista de noticias para %s vacía.', $label));

                return;
            }

            $this->reply->reply($sender, sprintf('Lista de noticias para %s:', $label));

            foreach ($items as $index => $item) {
                $this->reply->reply($sender, sprintf(
                    'Noticia numero %d añadida por %s el %s: %s',
                    $index + 1,
                    $item['author'],
                    $item['date'],
                    $item['text'],
                ));
            }

            $this->reply->reply($sender, 'Fin de la lista.');

            return;
        }

        $this->reply->reply($sender, sprintf('Error de Sintaxis: %s ADD|DEL|LIST [mensaje]', strtoupper($label)));
    }

    /** @param list<string> $args */
    private function onNick(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireAdmin($sender, $state)) {
            return;
        }

        $subcommand = array_shift($args);

        if (null === $subcommand) {
            $this->reply->reply($sender, 'Sintaxis incorrecta: NICK ADD / DEL / LIST');

            return;
        }

        $subcommand = strtoupper($subcommand);

        if ('ADD' === $subcommand) {
            $nick = array_shift($args);
            $text = implode(' ', $args);

            if (null === $nick || '' === $text) {
                $this->reply->reply($sender, 'Sintaxis incorrecta: NICK ADD nick mensaje');

                return;
            }

            if (null !== $this->motdNicks->get($nick)) {
                $this->reply->reply($sender, sprintf('ERROR: Ya existe un MOTD con el nick %s.', $nick));

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

            if ($this->isReservedNick($nick)) {
                $this->reply->reply($sender, sprintf('El nick %s es un bot de la red.', $nick));

                return;
            }

            $tempUid = $this->allocateTempUid($nick);

            $this->transport->writeLine($this->uidFormatter->format(
                nick: $nick,
                uid: $tempUid,
                realname: 'Network News Entry Bot',
                ident: $nick,
                host: $this->serviceHost($state),
            ));

            $this->motdNicks->add($nick, $text);
            $this->reply->reply($sender, sprintf('Mensaje de entrada a la red mediante el nick %s activado.', $nick));

            return;
        }

        if ('DEL' === $subcommand) {
            $nick = array_shift($args);

            if (null === $nick) {
                $this->reply->reply($sender, 'Sintaxis incorrecta: NICK DEL nick');

                return;
            }

            if (null === $this->motdNicks->get($nick)) {
                $this->reply->reply($sender, sprintf('ERROR: No existe un MOTD con el nick %s.', $nick));

                return;
            }

            $tempUid = $this->allocateTempUid($nick);
            $this->transport->writeLine(sprintf(':%s QUIT :MOTD desactivado.', $tempUid));
            $this->motdNicks->remove($nick);
            $this->reply->reply($sender, sprintf('Mensaje de entrada a la red mediante el nick %s borrado.', $nick));

            return;
        }

        if ('LIST' === $subcommand) {
            $nicks = $this->motdNicks->all();

            if ([] === $nicks) {
                $this->reply->reply($sender, 'No hay ningun MOTD por bot.');

                return;
            }

            $this->reply->reply($sender, 'Lista de nicks con MOTD:');

            foreach ($nicks as $nick => $message) {
                $this->reply->reply($sender, sprintf('%s: %s', $nick, $message));
            }

            $this->reply->reply($sender, 'Fin de la lista.');

            return;
        }

        $this->reply->reply($sender, 'Sintaxis incorrecta: NICK ADD / DEL / LIST');
    }

    private function onNoticias(string $sender, NetworkState $state): void
    {
        if (!$this->requireAdmin($sender, $state)) {
            return;
        }

        $url = $_ENV['DBOTS_NEWS_URL'] ?? '';

        if ('' === $url) {
            $this->reply->reply($sender, 'URL de noticias no configurada.');

            return;
        }

        $content = @file_get_contents($url);

        if (false === $content) {
            $this->reply->reply($sender, 'No se pudo conseguir la noticia del día.');

            return;
        }

        $content = trim($content);
        $maxLength = 400;

        if (strlen($content) > $maxLength) {
            $content = substr($content, 0, $maxLength) . '...';
        }

        $this->reply->reply($sender, $content);
    }

    /** @param list<string> $args */
    private function onServidor(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireAdmin($sender, $state)) {
            return;
        }

        $server = array_shift($args);

        if (null === $server) {
            $this->reply->reply($sender, 'Sintaxis incorrecta: SERVIDOR servidor [mensaje]');

            return;
        }

        $serverName = $this->resolveServerName($server, $state);

        if (null === $serverName) {
            $this->reply->reply($sender, sprintf('El servidor %s no está conectado ahora mismo.', $server));

            return;
        }

        $message = implode(' ', $args);

        if ('' === $message) {
            if (!$this->serverMessages->remove($serverName)) {
                $this->reply->reply($sender, sprintf('ERROR: No hay una noticia para el servidor %s', $server));

                return;
            }

            $this->reply->reply($sender, sprintf('Mensaje para los usuarios que conecten por el servidor %s quitado.', $server));

            return;
        }

        $this->serverMessages->set($serverName, $message);
        $this->reply->reply($sender, sprintf('Mensaje para los usuarios que conecten por el servidor %s añadido.', $server));
    }

    /** @param list<string> $args */
    private function onHelp(string $sender, array $args, NetworkState $state): void
    {
        $isOper = $this->hasRank($sender, $state, StaffRank::Administrador);

        foreach ($this->help->render(implode(' ', $args), $isOper) as $line) {
            $this->reply->reply($sender, $line);
        }
    }

    private function sendEmailTo(string $account, string $text): void
    {
        try {
            $nick = new Nick($account);
        } catch (InvalidArgumentException) {
            return;
        }

        $accountRecord = $this->accounts->findByNick($nick);

        if (null === $accountRecord || null === $accountRecord->email) {
            return;
        }

        $this->emailSender->send($accountRecord->email, 'Noticias de la Red', $text);
    }

    /**
     * @param array<string, string> $recipients uid => nick
     */
    private function broadcast(array $recipients, string $text, string $header): void
    {
        foreach ($recipients as $nick) {
            $this->transport->writeLine(sprintf(':%s PRIVMSG %s :%s', $this->serviceUid, $nick, $header));
            $this->transport->writeLine(sprintf(':%s PRIVMSG %s :%s', $this->serviceUid, $nick, $text));
        }
    }

    /**
     * @return array<string, string> uid => nick
     */
    private function staffRecipients(NetworkState $state, StaffRank $minRank): array
    {
        $recipients = [];

        foreach ($state->users() as $uid => $nick) {
            $account = $state->accountOf($uid);

            if ($this->staff->atLeast($account, $minRank)) {
                $recipients[$uid] = $nick;
            }
        }

        return $recipients;
    }

    private function resolveServerName(string $server, NetworkState $state): ?string
    {
        if (3 === strlen($server) && $state->isServer($server)) {
            return $state->serverNameOf($server) ?? $server;
        }

        foreach ($state->users() as $uid => $_) {
            $sid = substr($uid, 0, 3);
            $name = $state->serverNameOf($sid);

            if (null !== $name && 0 === strcasecmp($name, $server)) {
                return $name;
            }
        }

        return null;
    }

    private function requireIdentified(string $sender, NetworkState $state): bool
    {
        if ($state->hasMode($sender, 'r') && null !== $state->accountOf($sender)) {
            return true;
        }

        $this->reply->reply($sender, 'Debes identificarte para poder usar este servicio.');

        return false;
    }

    private function requireAdmin(string $sender, NetworkState $state): bool
    {
        if ($this->hasRank($sender, $state, StaffRank::Administrador)) {
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

    private function isReservedNick(string $nick): bool
    {
        if (0 === strcasecmp($nick, $this->serviceNick)) {
            return true;
        }

        foreach ($this->reservedNicks as $reserved) {
            if (0 === strcasecmp($nick, $reserved)) {
                return true;
            }
        }

        return false;
    }

    private function allocateTempUid(string $nick): string
    {
        $hash = strtoupper(substr(md5($nick), 0, 5));

        return sprintf('%sA9%s', $this->sid, $hash);
    }

    private function serviceHost(NetworkState $state): string
    {
        foreach ($state->users() as $uid => $_) {
            $host = $state->hostOf($uid);

            if (null !== $host) {
                return $host;
            }
        }

        return 'services.local';
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
