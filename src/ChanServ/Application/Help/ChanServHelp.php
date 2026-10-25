<?php

declare(strict_types=1);

namespace Dbots\ChanServ\Application\Help;

use Dbots\Irc\Application\Help\HelpCatalog;
use Dbots\Irc\Application\Help\HelpEntry;

final class ChanServHelp
{
    public static function create(): HelpCatalog
    {
        return new HelpCatalog('ChanServ', [
            new HelpEntry('REGISTER', 'REGISTER <#canal>', 'El registro se solicita a CReG y lo aprueba el staff.', '/msg CReG REGISTRA #miCanal', ['REGISTRA']),
            new HelpEntry('INFO', 'INFO <#canal>', 'Fundador y fecha de registro del canal.', '/msg ChanServ INFO #miCanal'),
            new HelpEntry('DROP', 'DROP <#canal>', 'Borra el registro del canal. Solo el fundador.', '/msg ChanServ DROP #miCanal'),
            new HelpEntry('ACCESS', 'ACCESS LIST|ADD|DEL <#canal> [nick]', 'Gestiona la lista de acceso del canal. Solo el fundador.', '/msg ChanServ ACCESS ADD #miCanal JuanJo'),
            new HelpEntry('TOPIC', 'TOPIC <#canal> <texto>', 'Fija el tema que el IRCd mantiene en el canal.', '/msg ChanServ TOPIC #miCanal Bienvenidos'),
        ]);
    }
}
