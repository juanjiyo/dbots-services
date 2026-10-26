<?php

declare(strict_types=1);

namespace Dbots\GlobalServ\Application\Help;

use Dbots\Irc\Application\Help\HelpCatalog;
use Dbots\Irc\Application\Help\HelpEntry;

final class GlobalServHelp
{
    public static function create(): HelpCatalog
    {
        return new HelpCatalog('GlobalServ', [
            new HelpEntry('GLOBAL', 'GLOBAL <mensaje>', 'Envía un mensaje a todos los usuarios conectados. Nivel Administrador.', '/msg GlobalServ GLOBAL Mantenimiento programado', operOnly: true),
            new HelpEntry('NICK', 'NICK <nick> <mensaje>', 'Envía un mensaje global usando un nick temporal. Nivel Administrador.', '/msg GlobalServ NICK Anuncio Mensaje de prueba', operOnly: true),
            new HelpEntry('CHAN', 'CHAN <#canal> <mensaje>', 'Envía un mensaje a todos los miembros de un canal. Nivel Administrador.', '/msg GlobalServ CHAN #ayuda Bienvenidos', operOnly: true),
            new HelpEntry('JOIN', 'JOIN <#canal> <motivo>', 'Fuerza a todos los usuarios a entrar en un canal. Nivel Root.', '/msg GlobalServ JOIN #reunion Aviso importante', operOnly: true),
            new HelpEntry('PRE-OPER', 'PRE-OPER <mensaje>', 'Envía un mensaje a Pre-Operadores y superiores. Nivel Administrador.', '/msg GlobalServ PRE-OPER Aviso al staff', operOnly: true),
            new HelpEntry('OPER', 'OPER <mensaje>', 'Envía un mensaje a Operadores y superiores. Nivel Administrador.', '/msg GlobalServ OPER Aviso al staff', operOnly: true),
            new HelpEntry('CO-ADMIN', 'CO-ADMIN <mensaje>', 'Envía un mensaje a Administradores y superiores. Nivel Root.', '/msg GlobalServ CO-ADMIN Aviso a administración', operOnly: true),
            new HelpEntry('ADMIN', 'ADMIN <mensaje>', 'Envía un mensaje a Administradores. Nivel Root.', '/msg GlobalServ ADMIN Aviso a administración', operOnly: true),
        ]);
    }
}
