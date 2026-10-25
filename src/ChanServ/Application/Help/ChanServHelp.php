<?php

declare(strict_types=1);

namespace Dbots\ChanServ\Application\Help;

use Dbots\Irc\Application\Help\HelpCatalog;
use Dbots\Irc\Application\Help\HelpEntry;

final class ChanServHelp
{
    public static function create(): HelpCatalog
    {
        return new HelpCatalog('CHaN', [
            new HelpEntry('REGISTER', 'REGISTER <#canal>', 'El registro se solicita a CReG y lo aprueba el staff.', '/msg CReG REGISTRA #miCanal', ['REGISTRA']),
            new HelpEntry('INFO', 'INFO <#canal>', 'Fundador y fecha de registro del canal.', '/msg CHaN INFO #miCanal'),
            new HelpEntry('DROP', 'DROP <#canal>', 'Borra el registro del canal. Solo el fundador.', '/msg CHaN DROP #miCanal'),
            new HelpEntry('ACCESS', 'ACCESS LIST|ADD|DEL <#canal> [nick]', 'Gestiona la lista de acceso del canal. Solo el fundador.', '/msg CHaN ACCESS ADD #miCanal JuanJo'),
            new HelpEntry('IDENTIFY', 'IDENTIFY <#canal> <contraseña>', 'Te identifica como fundador con la contraseña del canal, aunque no tengas tu nick identificado.', '/msg CHaN IDENTIFY #miCanal miClave'),
            new HelpEntry('TOPIC', 'TOPIC <#canal> <texto>', 'Fija el tema que el IRCd mantiene en el canal.', '/msg CHaN TOPIC #miCanal Bienvenidos'),
            new HelpEntry('LEVELS', 'LEVELS <#canal> LIST|SET|RESET [clave] [nivel]', 'Niveles que exige cada comando del canal. Van de -1 a 500; 500 es el fundador. Solo el fundador.', '/msg CHaN LEVELS #miCanal SET AUTOOP 300'),
            new HelpEntry('SET', 'SET <#canal> <opción> [valor]', 'Cambia la configuración del canal. Opciones: DESC, URL, EMAIL, ENTRYMSG y PASSWORD. Solo el fundador.', '/msg CHaN SET #miCanal ENTRYMSG Bienvenidos al canal'),
        ]);
    }
}
