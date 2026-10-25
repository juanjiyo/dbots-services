<?php

declare(strict_types=1);

namespace Dbots\CenTeRServ\Application\Help;

use Dbots\Irc\Application\Help\HelpCatalog;
use Dbots\Irc\Application\Help\HelpEntry;

final class CenTeRServHelp
{
    public static function create(): HelpCatalog
    {
        return new HelpCatalog('CeNTeR', [
            new HelpEntry('PREOPER', 'PREOPER ADD|DEL <nick>', 'Da o quita el rango de Pre-Operador. Nivel Administrador.', '/msg CeNTeR PREOPER ADD JuanJo'),
            new HelpEntry('OPER', 'OPER ADD|DEL <nick>', 'Da o quita el rango de Operador. Nivel Administrador.', '/msg CeNTeR OPER ADD JuanJo'),
            new HelpEntry('ADMIN', 'ADMIN ADD|DEL <nick>', 'Da o quita el rango de Administrador. Nivel Root.', '/msg CeNTeR ADMIN ADD JuanJo'),
            new HelpEntry('REMOPER', 'REMOPER <nick>', 'Quita cualquier rango de la red. Nivel Root.', '/msg CeNTeR REMOPER JuanJo'),
            new HelpEntry('ROOT', 'ROOT <contraseña> | ROOT ADD|DEL <nick>', 'Sin argumentos de rango abre una sesión de Root; con ADD o DEL gestiona el rango Root.', '/msg CeNTeR ROOT miClave'),
            new HelpEntry('MODULO', 'MODULO LIST|LOAD|UNLOAD [nombre]', 'Lista, activa o desactiva los módulos de `modules/`. Nivel Root, porque un módulo corre dentro del daemon.', '/msg CeNTeR MODULO LIST'),
        ]);
    }
}
