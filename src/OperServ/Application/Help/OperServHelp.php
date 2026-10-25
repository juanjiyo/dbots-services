<?php

declare(strict_types=1);

namespace Dbots\OperServ\Application\Help;

use Dbots\Irc\Application\Help\HelpCatalog;
use Dbots\Irc\Application\Help\HelpEntry;

final class OperServHelp
{
    public static function create(): HelpCatalog
    {
        return new HelpCatalog('OPeR', [
            new HelpEntry('KILL', 'KILL <nick> [motivo]', 'Expulsa a un usuario del IRC.', '/msg OPeR KILL JuanJo flood', operOnly: true),
            new HelpEntry('KICK', 'KICK <#canal> <nick> [motivo]', 'Expulsa a un usuario de un canal.', '/msg OPeR KICK #canal JuanJo flood', operOnly: true),
            new HelpEntry('OP', 'OP <#canal> <nick>', 'Da +o en el canal.', '/msg OPeR OP #canal JuanJo', operOnly: true),
            new HelpEntry('DEOP', 'DEOP <#canal> <nick>', 'Quita +o en el canal.', '/msg OPeR DEOP #canal JuanJo', operOnly: true),
            new HelpEntry('MODE', 'MODE <#canal> <modos> [argumento]', 'Aplica modos de canal como servidor.', '/msg OPeR MODE #canal +m', operOnly: true),
            new HelpEntry('GLINE', 'GLINE <usuario@host> <duración> [motivo]', 'Banea un host. La duración va en minutos o con sufijo: 30m, 2h, 1d, 0 para permanente.', '/msg OPeR GLINE *@malo.example 2h flood', operOnly: true),
            new HelpEntry('UNGLINE', 'UNGLINE <usuario@host>', 'Quita el G-Line.', '/msg OPeR UNGLINE *@malo.example', operOnly: true),
            new HelpEntry('RENAME', 'RENAME <nick> [nuevoNick]', 'Cambia el nick a un usuario conectado.', '/msg OPeR RENAME JuanJo JuanJo_', operOnly: true),
            new HelpEntry('DROPNDB', 'DROPNDB <nick>', 'Borra un nick de la base de datos.', '/msg OPeR DROPNDB JuanJo', operOnly: true),
            new HelpEntry('APODERA', 'APODERA <#canal> [nick]', 'Te hace dueño de un canal registrado.', '/msg OPeR APODERA #canal JuanJo', operOnly: true),
            new HelpEntry('LISTCLONES', 'LISTCLONES <nick>', 'Muestra quién comparte host con ese nick.', '/msg OPeR LISTCLONES JuanJo', operOnly: true),
            new HelpEntry('KILLCLONES', 'KILLCLONES <nick> [motivo]', 'Banea el host del nick durante una hora.', '/msg OPeR KILLCLONES JuanJo clones', operOnly: true),
            new HelpEntry('BLOCK', 'BLOCK <nick> [motivo]', 'Banea el host del nick durante cinco horas.', '/msg OPeR BLOCK JuanJo spam', operOnly: true),
            new HelpEntry('JAIL', 'JAIL <nick>', 'Saca al usuario de todos los canales, le pone +C y lo fuerza al canal carcel.', '/msg OPeR JAIL JuanJo', operOnly: true),
            new HelpEntry('UMODE', 'UMODE <nick> +/-modos', 'Cambia los modos de usuario de un nick. No permite r ni h.', '/msg OPeR UMODE JuanJo +w', operOnly: true),
            new HelpEntry('ELINE', 'ELINE ADD|DEL|LIST|VIEW|COUNT ...', 'Gestiona excepciones E-Line por IP (requiere Administrador).', '/msg OPeR ELINE ADD *@192.0.2.1 2h motivo', operOnly: true),
            new HelpEntry('SERVERMAP', 'SERVERMAP', 'Muestra el árbol de servidores de la red (requiere Administrador).', '/msg OPeR SERVERMAP', operOnly: true),
            new HelpEntry('CONEXIONES', 'CONEXIONES LIST <min>|VIEW <host>', 'Lista sesiones por host o muestra una IP (requiere Administrador).', '/msg OPeR CONEXIONES LIST 3', operOnly: true),
            new HelpEntry('LIMPIA', 'LIMPIA <#canal>', 'Quita los modos comunes del canal (-imnpst).', '/msg OPeR LIMPIA #canal', operOnly: true),
            new HelpEntry('SPAM', 'SPAM LIST|ADD|DEL <patrón> <acción>', 'Gestiona las reglas de spamfilter.', '/msg OPeR SPAM ADD *http://malo* gline', operOnly: true),
            new HelpEntry('STATS', 'STATS', 'Usuarios conectados.', '/msg OPeR STATS', operOnly: true),
            new HelpEntry('IRCOPS', 'IRCOPS', 'Lista los operadores conectados.', '/msg OPeR IRCOPS', operOnly: true),
            new HelpEntry('INVITE', 'INVITE <#canal>', 'Te invita a un canal.', '/msg OPeR INVITE #canal', operOnly: true),
            new HelpEntry('SET', 'SET SUFIJO|NICKSERV|CHANSERV|IPSERV|PASSPROTECT|PREFIJOS <valor>', 'Configura valores del bloque S de UDB.', '/msg OPeR SET NICKSERV NickServ!NickServ@bots.sitiochat.es', operOnly: true),
            new HelpEntry('ROOT', 'ROOT <contraseña>', 'Te identifica como root, un nivel por encima de oper.', '/msg OPeR ROOT miClaveRoot'),
            new HelpEntry('SETTIME', 'SETTIME', 'Resincroniza el reloj de la red. Requiere root.', '/msg OPeR SETTIME'),
        ]);
    }
}
