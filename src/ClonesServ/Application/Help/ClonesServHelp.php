<?php

declare(strict_types=1);

namespace Dbots\ClonesServ\Application\Help;

use Dbots\Irc\Application\Help\IrcFormat;

final readonly class ClonesServHelp
{
    /**
     * @return list<string>
     */
    public static function render(): array
    {
        $p = self::prefix();

        return [
            $p . ' ' . IrcFormat::paint(IrcFormat::HEADER, 'SitioChat') . ' — Gestión de ilines (clones) para InspIRCd 4',
            $p . ' ',
            $p . ' ' . "\x02" . '═══════ Órdenes disponibles ═══════' . "\x02",
            $p . ' ' . IrcFormat::paint(IrcFormat::COMMAND, 'help') . '              Muestra esta ayuda',
            $p . ' ' . IrcFormat::paint(IrcFormat::COMMAND, 'perm <IP> <N>') . '      Iline permanente (N clones)',
            $p . ' ' . IrcFormat::paint(IrcFormat::COMMAND, 'add <IP> <N> <t>') . '   Iline temporal (t = 30d, 6m, 1y)',
            $p . ' ' . IrcFormat::paint(IrcFormat::COMMAND, 'renew <IP> <t>') . '     Renovar expiración (mantiene N)',
            $p . ' ' . IrcFormat::paint(IrcFormat::COMMAND, 'edit <IP> <N> <t>') . '  Cambiar N y expiración',
            $p . ' ' . IrcFormat::paint(IrcFormat::COMMAND, 'del <IP>') . '            Eliminar una iline',
            $p . ' ' . IrcFormat::paint(IrcFormat::COMMAND, 'info [IP]') . '           Info de tu iline o de una IP',
            $p . ' ' . IrcFormat::paint(IrcFormat::COMMAND, 'list') . '               Lista todas las ilines activas',
            $p . ' ',
            $p . ' ' . "\x02" . '═══════ FIN de la AYUDA ═══════' . "\x02",
        ];
    }

    public static function prefix(): string
    {
        return "\x030,3\x02 CLoNeS \x02\x0F";
    }
}
