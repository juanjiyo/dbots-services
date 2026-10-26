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
            new HelpEntry('LIST', 'LIST <patrón>', 'Busca canales registrados por patrón. Solo operadores.', '/msg CHaN LIST #siti*'),
            new HelpEntry('GETPASS', 'GETPASS <#canal>', 'La contraseña está cifrada: el comando lo dice y remite a SET PASSWORD. Solo operadores.', '/msg CHaN GETPASS #miCanal'),
            new HelpEntry('FORBID', 'FORBID <#canal> <motivo>', 'Prohíbe el canal y borra su registro: nadie podrá registrarlo. Solo operadores.', '/msg CHaN FORBID #miCanal spam'),
            new HelpEntry('UNFORBID', 'UNFORBID <#canal>', 'Levanta la prohibición de un canal. Solo operadores.', '/msg CHaN UNFORBID #miCanal'),
            new HelpEntry('LISTFORBID', 'LISTFORBID', 'Lista los canales prohibidos con su motivo. Solo operadores.', '/msg CHaN LISTFORBID'),
            new HelpEntry('SUSPEND', 'SUSPEND <#canal> <motivo>', 'Suspende el canal: pierde +r, no se dan +q y quien entra ve el motivo. Solo operadores.', '/msg CHaN SUSPEND #miCanal incumple normas'),
            new HelpEntry('UNSUSPEND', 'UNSUSPEND <#canal>', 'Levanta la suspensión. Solo operadores.', '/msg CHaN UNSUSPEND #miCanal'),
            new HelpEntry('LISTSUSPEND', 'LISTSUSPEND', 'Lista los canales suspendidos con su motivo. Solo operadores.', '/msg CHaN LISTSUSPEND'),
            new HelpEntry('AKICK', 'AKICK <#canal> ADD|DEL|LIST|ENFORCE [nick] [motivo]', 'Lista de autokick del canal: el nick entra y sale expulsado. Nivel AKICK-CHANGE para cambiarla, AKICK-LIST para verla.', '/msg CHaN AKICK #miCanal ADD Nick molesta'),
            new HelpEntry('CLEAR', 'CLEAR <#canal> MODES|BANS|OPS|HALFOPS|VOICES|USERS|TOPIC', 'Limpia el canal de golpe. Nivel CLEAR (solo fundador por defecto).', '/msg CHaN CLEAR #miCanal OPS'),
            new HelpEntry('OP', 'OP <#canal> <nick>', 'Da +o en el canal. Nivel OPDEOP.', '/msg CHaN OP #miCanal JuanJo'),
            new HelpEntry('DEOP', 'DEOP <#canal> <nick>', 'Quita +o. Nivel OPDEOP y por encima del afectado.', '/msg CHaN DEOP #miCanal JuanJo'),
            new HelpEntry('HALFOP', 'HALFOP <#canal> <nick>', 'Da +h en el canal. Nivel HALFOPDEHALFOP.', '/msg CHaN HALFOP #miCanal JuanJo'),
            new HelpEntry('DEHALFOP', 'DEHALFOP <#canal> <nick>', 'Quita +h. Nivel HALFOPDEHALFOP y por encima del afectado.', '/msg CHaN DEHALFOP #miCanal JuanJo'),
            new HelpEntry('VOICE', 'VOICE <#canal> <nick>', 'Da +v en el canal. Nivel VOICEDEVOICE.', '/msg CHaN VOICE #miCanal JuanJo'),
            new HelpEntry('DEVOICE', 'DEVOICE <#canal> <nick>', 'Quita +v. Nivel VOICEDEVOICE y por encima del afectado.', '/msg CHaN DEVOICE #miCanal JuanJo'),
            new HelpEntry('INVITE', 'INVITE <#canal>', 'Te invita al canal. Nivel INVITE.', '/msg CHaN INVITE #miCanal'),
            new HelpEntry('UNBAN', 'UNBAN <#canal>', 'Levanta tu ban en el canal. Nivel UNBAN.', '/msg CHaN UNBAN #miCanal'),
            new HelpEntry('LEVELS', 'LEVELS <#canal> LIST|SET|RESET [clave] [nivel]', 'Niveles que exige cada comando del canal. Van de -1 a 500; 500 es el fundador. Solo el fundador.', '/msg CHaN LEVELS #miCanal SET AUTOOP 300'),
            new HelpEntry('SET', 'SET <#canal> <opción> [valor]', 'Configuración del canal: DESC, URL, EMAIL, ENTRYMSG, PASSWORD, MLOCK, FOUNDER, SUCCESSOR y los interruptores ON|OFF (TOPICLOCK, KEEPTOPIC, SECUREOPS, SECUREHALFOPS, AUTOLIMIT, PBANS, PMODE, PRIVATE, SECURE, RESTRICTED, NOEXPIRE, DEBUG). Solo el fundador.', '/msg CHaN SET #miCanal TOPICLOCK ON'),
            new HelpEntry('SET FOUNDER', 'SET <#canal> FOUNDER <nick>', 'Transfiere la propiedad del canal a otro nick registrado.', '/msg CHaN SET #miCanal FOUNDER bob'),
            new HelpEntry('SET SUCCESSOR', 'SET <#canal> SUCCESSOR [nick]', 'Fija o borra el sucesor del canal.', '/msg CHaN SET #miCanal SUCCESSOR bob'),
        ]);
    }
}
