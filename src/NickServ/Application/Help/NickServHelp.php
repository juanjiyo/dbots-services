<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\Help;

use Dbots\Irc\Application\Help\HelpCatalog;
use Dbots\Irc\Application\Help\HelpEntry;

final class NickServHelp
{
    public static function create(): HelpCatalog
    {
        return new HelpCatalog('NiCK', [
            new HelpEntry('REGISTER', 'REGISTER <contraseña> [email]', 'Registra tu nick actual.', '/msg NiCK REGISTER miClave correo@ejemplo.org', ['REGISTRA']),
            new HelpEntry('IDENTIFY', 'IDENTIFY <contraseña>', 'Comprueba tu contraseña. Para identificarte de verdad, reconecta con /NICK nick:contraseña.', '/msg NiCK IDENTIFY miClave', ['IDENTIFICA']),
            new HelpEntry('INFO', 'INFO <nick>', 'Muestra los datos de una cuenta.', '/msg NiCK INFO JuanJo'),
            new HelpEntry('STATUS', 'STATUS [nick]', 'Estado de la cuenta: alta, prohibición, suspensión y NOEXPIRE.', '/msg NiCK STATUS JuanJo', ['ESTADO']),
            new HelpEntry('SET', 'SET PASSWORD <actual> <nueva> | SET EMAIL|URL|QUIT|PRIVATE|KILL|AUTOMODE <valor>', 'Cambia la contraseña y otras opciones de tu cuenta.', '/msg NiCK SET EMAIL correo@ejemplo.org'),
            new HelpEntry('DROP', 'DROP <contraseña>', 'Borra tu cuenta. Antes hay que soltar los canales que fundaste.', '/msg NiCK DROP miClave'),
            new HelpEntry('SUSPEND', 'SUSPEND <nick> [motivo]', 'Suspende una cuenta: podrá entrar, pero sin cuenta ni +r.', '/msg NiCK SUSPEND JuanJo abuso', operOnly: true),
            new HelpEntry('UNSUSPEND', 'UNSUSPEND <nick>', 'Levanta la suspensión.', '/msg NiCK UNSUSPEND JuanJo', operOnly: true),
            new HelpEntry('LISTSUSPEND', 'LISTSUSPEND', 'Lista las cuentas suspendidas.', '/msg NiCK LISTSUSPEND', operOnly: true),
            new HelpEntry('FORBID', 'FORBID <nick> [motivo]', 'Prohíbe un nick: no se podrá usar ni registrar.', '/msg NiCK FORBID JuanJo suplantación', operOnly: true),
            new HelpEntry('UNFORBID', 'UNFORBID <nick>', 'Levanta la prohibición.', '/msg NiCK UNFORBID JuanJo', operOnly: true),
            new HelpEntry('LISTFORBID', 'LISTFORBID', 'Lista los nicks prohibidos.', '/msg NiCK LISTFORBID', operOnly: true),
            new HelpEntry('SWHOIS', 'SWHOIS <nick> <texto>', 'Fija el SWHOIS que verá quien haga /WHOIS.', '/msg NiCK SWHOIS JuanJo Miembro del staff', operOnly: true),
            new HelpEntry('NOEXPIRE', 'NOEXPIRE <nick>', 'Activa o desactiva la exención de caducidad.', '/msg NiCK NOEXPIRE JuanJo', operOnly: true),
            new HelpEntry('SENDPASS', 'SENDPASS <nick>', 'Genera una contraseña temporal y la envía por memo a la cuenta.', '/msg NiCK SENDPASS JuanJo', operOnly: true),
        ]);
    }
}
