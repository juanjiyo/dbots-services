<?php

declare(strict_types=1);

namespace Dbots\OperServ\Application\Help;

use Dbots\Irc\Application\Help\HelpCatalog;
use Dbots\Irc\Application\Help\HelpEntry;

final class OperServHelp
{
    public static function create(): HelpCatalog
    {
        return new HelpCatalog('OperServ', [
            new HelpEntry('KILL', 'KILL <nick> [motivo]', 'Expulsa a un usuario del IRC.', '/msg OperServ KILL JuanJo flood', operOnly: true),
            new HelpEntry('KICK', 'KICK <#canal> <nick> [motivo]', 'Expulsa a un usuario de un canal.', '/msg OperServ KICK #canal JuanJo flood', operOnly: true),
            new HelpEntry('OP', 'OP <#canal> <nick>', 'Da +o en el canal.', '/msg OperServ OP #canal JuanJo', operOnly: true),
            new HelpEntry('DEOP', 'DEOP <#canal> <nick>', 'Quita +o en el canal.', '/msg OperServ DEOP #canal JuanJo', operOnly: true),
            new HelpEntry('MODE', 'MODE <#canal> <modos> [argumento]', 'Aplica modos de canal como servidor.', '/msg OperServ MODE #canal +m', operOnly: true),
            new HelpEntry('GLINE', 'GLINE <usuario@host> <duración> [motivo]', 'Banea un host. La duración va en minutos o con sufijo: 30m, 2h, 1d, 0 para permanente.', '/msg OperServ GLINE *@malo.example 2h flood', operOnly: true),
            new HelpEntry('UNGLINE', 'UNGLINE <usuario@host>', 'Quita el G-Line.', '/msg OperServ UNGLINE *@malo.example', operOnly: true),
            new HelpEntry('RENAME', 'RENAME <nick> [nuevoNick]', 'Cambia el nick a un usuario conectado.', '/msg OperServ RENAME JuanJo JuanJo_', operOnly: true),
            new HelpEntry('DROPNDB', 'DROPNDB <nick>', 'Borra un nick de la base de datos.', '/msg OperServ DROPNDB JuanJo', operOnly: true),
            new HelpEntry('APODERA', 'APODERA <#canal> [nick]', 'Te hace dueño de un canal registrado.', '/msg OperServ APODERA #canal JuanJo', operOnly: true),
            new HelpEntry('LISTCLONES', 'LISTCLONES <nick>', 'Muestra quién comparte host con ese nick.', '/msg OperServ LISTCLONES JuanJo', operOnly: true),
            new HelpEntry('KILLCLONES', 'KILLCLONES <nick> [motivo]', 'Banea el host del nick durante una hora.', '/msg OperServ KILLCLONES JuanJo clones', operOnly: true),
            new HelpEntry('BLOCK', 'BLOCK <nick> [motivo]', 'Banea el host del nick durante cinco horas.', '/msg OperServ BLOCK JuanJo spam', operOnly: true),
            new HelpEntry('LIMPIA', 'LIMPIA <#canal>', 'Quita los modos comunes del canal (-imnpst).', '/msg OperServ LIMPIA #canal', operOnly: true),
            new HelpEntry('SPAM', 'SPAM LIST|ADD|DEL <patrón> <acción>', 'Gestiona las reglas de spamfilter.', '/msg OperServ SPAM ADD *http://malo* gline', operOnly: true),
            new HelpEntry('STATS', 'STATS', 'Usuarios conectados.', '/msg OperServ STATS', operOnly: true),
            new HelpEntry('IRCOPS', 'IRCOPS', 'Lista los operadores conectados.', '/msg OperServ IRCOPS', operOnly: true),
            new HelpEntry('ROOT', 'ROOT <contraseña>', 'Te identifica como root, un nivel por encima de oper.', '/msg OperServ ROOT miClaveRoot'),
            new HelpEntry('SETTIME', 'SETTIME', 'Resincroniza el reloj de la red. Requiere root.', '/msg OperServ SETTIME'),
        ]);
    }
}
