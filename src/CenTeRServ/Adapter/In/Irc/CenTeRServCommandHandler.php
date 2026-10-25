<?php

declare(strict_types=1);

namespace Dbots\CenTeRServ\Adapter\In\Irc;

use Dbots\Domain\Account\Nick;
use Dbots\Domain\Staff\StaffRank;
use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\AccountResolver;
use Dbots\Irc\Application\Help\HelpCatalog;
use Dbots\Irc\Application\NetworkState;
use Dbots\Module\ModuleRuntime;
use Dbots\NickServ\Application\Port\Out\AccountRepositoryInterface;
use Dbots\NickServ\Application\Port\Out\ReplyInterface;
use Dbots\Staff\Application\Port\Out\StaffRankRepositoryInterface;
use Dbots\Staff\Application\StaffAuthority;
use InvalidArgumentException;

use function array_filter;
use function array_shift;
use function array_values;
use function explode;
use function hash_equals;
use function implode;
use function in_array;
use function sprintf;
use function strcasecmp;
use function strtoupper;

/**
 * CeNTeR — the control service. It manages the staff ladder and the module
 * system; every other command in `ce.mrc` either duplicated OPeR or drove
 * machinery this daemon does not have (see `odd/tasks/centerserv.md`).
 *
 * The dBoTs entry gate was `c.r CA` (co-administrator). With Co-Administrador
 * removed by decision, the rung above Operador is Administrador, so that is the
 * gate here. Root is required for anything that hands out Administrador or above,
 * and for loading code.
 */
final class CenTeRServCommandHandler
{
    /** @var array<string, true> senders with a Root session open */
    private array $roots = [];

    public function __construct(
        private readonly ReplyInterface $reply,
        private readonly StaffAuthority $staff,
        private readonly StaffRankRepositoryInterface $ranks,
        private readonly AccountRepositoryInterface $accounts,
        private readonly ModuleRuntime $modules,
        private readonly AccountResolver $resolver,
        private readonly string $serviceNick,
        private readonly string $serviceUid,
        private readonly HelpCatalog $help,
        private readonly string $rootPassword = '',
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
            $this->onHelp($sender, [], $state);

            return;
        }

        $command = strtoupper((string) array_shift($parts));
        $account = $this->resolver->resolve($sender, $state);

        match ($command) {
            'HELP', 'AYUDA' => $this->onHelp($sender, $parts, $state),
            'PREOPER' => $this->onRank($sender, $account, StaffRank::PreOperador, $parts, $state),
            'OPER' => $this->onRank($sender, $account, StaffRank::Operador, $parts, $state),
            'ADMIN' => $this->onRank($sender, $account, StaffRank::Administrador, $parts, $state),
            'ROOT' => $this->onRoot($sender, $account, $parts, $state),
            'REMOPER' => $this->onRemoper($sender, $account, $parts, $state),
            'MODULO' => $this->onModule($sender, $account, $parts, $state),
            default => $this->reply->reply($sender, sprintf('Comando desconocido %s. Usa AYUDA.', $command)),
        };
    }

    /** @param list<string> $args */
    private function onRank(
        string $uid,
        ?string $account,
        StaffRank $rank,
        array $args,
        NetworkState $state,
    ): void {
        $action = strtoupper((string) ($args[0] ?? ''));
        $nick = (string) ($args[1] ?? '');

        if (!in_array($action, ['ADD', 'DEL'], true) || '' === $nick) {
            $this->reply->reply($uid, sprintf('Error de Sintaxis: %s ADD|DEL <nick>', $rank->value));

            return;
        }

        // Handing out Administrador or above is a Root act; below it, the
        // administrative gate is enough.
        $required = $rank->level() >= StaffRank::Administrador->level()
            ? StaffRank::Root
            : StaffRank::Administrador;

        if (!$this->mayUse($uid, $account, $required, $state)) {
            return;
        }

        try {
            $target = new Nick($nick);
        } catch (InvalidArgumentException) {
            $this->reply->reply($uid, sprintf('El nick %s no está registrado.', $nick));

            return;
        }

        if (null === $this->accounts->findByNick($target)) {
            $this->reply->reply($uid, sprintf('El nick %s no está registrado.', $nick));

            return;
        }

        $current = $this->ranks->rankOf($target->value);

        if ('DEL' === $action) {
            if ($current !== $rank) {
                $this->reply->reply($uid, sprintf('%s no es un %s de la red.', $target->value, $rank->label()));

                return;
            }

            $this->ranks->revoke($target->value);
            $this->reply->reply($uid, sprintf('%s borrado de %s de la red.', $target->value, $rank->label()));

            return;
        }

        if (null !== $current) {
            $this->reply->reply($uid, sprintf('%s ya es un representante de la red.', $target->value));

            return;
        }

        $this->ranks->assign($target->value, $rank);
        $this->reply->reply($uid, sprintf('%s añadido como %s de la red.', $target->value, $rank->label()));
    }

    /**
     * `ROOT <password>` opens a Root session, which is how the first Root exists
     * at all; `ROOT ADD|DEL <nick>` moves somebody on the ladder.
     *
     * @param list<string> $args
     */
    private function onRoot(string $uid, ?string $account, array $args, NetworkState $state): void
    {
        $first = strtoupper((string) ($args[0] ?? ''));

        if (in_array($first, ['ADD', 'DEL'], true)) {
            $this->onRank($uid, $account, StaffRank::Root, $args, $state);

            return;
        }

        if ('' === $this->rootPassword) {
            $this->reply->reply($uid, 'Root no está configurado en este servicio.');

            return;
        }

        if ('' === $first) {
            $this->reply->reply($uid, 'Error de Sintaxis: ROOT <contraseña>');

            return;
        }

        if (!hash_equals($this->rootPassword, (string) $args[0])) {
            $this->reply->reply($uid, 'Contraseña incorrecta.');

            return;
        }

        $this->roots[$uid] = true;
        $this->reply->reply($uid, 'Ahora estás identificado como Root.');
    }

    /** @param list<string> $args */
    private function onRemoper(string $uid, ?string $account, array $args, NetworkState $state): void
    {
        $nick = (string) ($args[0] ?? '');

        if ('' === $nick) {
            $this->reply->reply($uid, 'Sintaxis incorrecta: REMOPER <nick>');

            return;
        }

        if (!$this->mayUse($uid, $account, StaffRank::Root, $state)) {
            return;
        }

        if (!$this->ranks->revoke($nick)) {
            $this->reply->reply($uid, sprintf('%s no tiene ningún rango de la red.', $nick));

            return;
        }

        $this->reply->reply($uid, sprintf('Quitados los rangos de la red a %s.', $nick));
    }

    /** @param list<string> $args */
    private function onModule(string $uid, ?string $account, array $args, NetworkState $state): void
    {
        if (!$this->mayUse($uid, $account, StaffRank::Root, $state)) {
            return;
        }

        $action = strtoupper((string) ($args[0] ?? 'LIST'));
        $name = (string) ($args[1] ?? '');
        $manifests = $this->modules->registry()->manifests();

        if ('LIST' === $action) {
            if ([] === $manifests) {
                $this->reply->reply($uid, 'No hay módulos disponibles.');

                return;
            }

            $this->reply->reply($uid, 'Lista de módulos cargados y descargados:');

            foreach ($manifests as $moduleName => $manifest) {
                $enabled = $this->modules->registry()->state()->isEnabled($moduleName, $manifest->enabledByDefault);
                $this->reply->reply($uid, sprintf(
                    '%s: %s — %s',
                    $moduleName,
                    $enabled ? 'cargado' : 'descargado',
                    $manifest->description,
                ));
            }

            $this->reply->reply($uid, 'Fin de la lista.');

            return;
        }

        if (!in_array($action, ['LOAD', 'UNLOAD'], true) || '' === $name) {
            $this->reply->reply($uid, 'Error de Sintaxis: MODULO LIST|LOAD|UNLOAD <nombre>');

            return;
        }

        if (!isset($manifests[$name])) {
            $this->reply->reply($uid, sprintf('El módulo %s no existe.', $name));

            return;
        }

        $this->modules->registry()->state()->setEnabled($name, 'LOAD' === $action);

        try {
            $this->modules->refresh();
        } catch (\Throwable $error) {
            $this->reply->reply($uid, sprintf('Error al aplicar el cambio: %s', $error->getMessage()));

            return;
        }

        $this->reply->reply($uid, sprintf(
            'Módulo %s %s.',
            $name,
            'LOAD' === $action ? 'cargado' : 'descargado',
        ));
    }

    /** @param list<string> $args */
    private function onHelp(string $uid, array $args, NetworkState $state): void
    {
        $isOper = $this->mayUseSilently($uid, $state);

        foreach ($this->help->render(implode(' ', $args), $isOper) as $line) {
            $this->reply->reply($uid, $line);
        }
    }

    /** Root session, Root rank, or an IRC oper with enough standing. */
    private function mayUse(string $uid, ?string $account, StaffRank $required, NetworkState $state): bool
    {
        if ($this->mayUseSilently($uid, $state, $required)) {
            return true;
        }

        $this->reply->reply($uid, 'Permiso denegado.');

        return false;
    }

    private function mayUseSilently(string $uid, NetworkState $state, ?StaffRank $required = null): bool
    {
        $required ??= StaffRank::Administrador;

        if (isset($this->roots[$uid])) {
            return true;
        }

        return $this->staff->atLeast($state->accountOf($uid), $required);
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
