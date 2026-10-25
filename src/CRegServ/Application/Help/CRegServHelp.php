<?php

declare(strict_types=1);

namespace Dbots\CRegServ\Application\Help;

use Dbots\Irc\Application\Help\HelpCatalog;
use Dbots\Irc\Application\Help\HelpEntry;

final class CRegServHelp
{
    public static function create(): HelpCatalog
    {
        return new HelpCatalog('CReG', [
            new HelpEntry('REGISTRA', 'REGISTRA <#canal> [descripción]', 'Solicita registrar un canal. Debes tener @ en el canal y estar identificado.', '/msg CReG REGISTRA #miCanal Mi canal', ['REGISTER']),
            new HelpEntry('ESTADO', 'ESTADO <#canal>', 'Estado de la solicitud: pendiente, aceptada o denegada.', '/msg CReG ESTADO #miCanal'),
            new HelpEntry('CANCELA', 'CANCELA <#canal>', 'Retira tu solicitud pendiente.', '/msg CReG CANCELA #miCanal'),
            new HelpEntry('LISTA', 'LISTA [estado]', 'Lista las solicitudes.', '/msg CReG LISTA PENDIENTE', operOnly: true),
            new HelpEntry('INFO', 'INFO <#canal>', 'Datos completos de una solicitud, con sus marcas.', '/msg CReG INFO #miCanal', operOnly: true),
            new HelpEntry('ACEPTA', 'ACEPTA <#canal>', 'Aprueba la solicitud y registra el canal. Nivel de operador.', '/msg CReG ACEPTA #miCanal', operOnly: true),
            new HelpEntry('DENIEGA', 'DENIEGA <#canal> [motivo]', 'Deniega una solicitud pendiente. Nivel de operador.', '/msg CReG DENIEGA #miCanal no procede', operOnly: true),
            new HelpEntry('LIBERA', 'LIBERA <#canal>', 'Borra una solicitud denegada para que pueda volver a pedirse.', '/msg CReG LIBERA #miCanal', operOnly: true),
            new HelpEntry('ADMIN', 'ADMIN ADD|DEL|LIST [nick]', 'Gestiona quién puede aprobar registros.', '/msg CReG ADMIN ADD JuanJo', operOnly: true),
            new HelpEntry('OFICIAL', 'OFICIAL <#canal> [descripción]', 'Registra un canal oficial de la red.', '/msg CReG OFICIAL #SitioChat', operOnly: true),
            new HelpEntry('COMERCIAL', 'COMERCIAL <#canal> <fundador> [descripción]', 'Registra un canal comercial a nombre de otro.', '/msg CReG COMERCIAL #tienda JuanJo', operOnly: true),
        ]);
    }
}
