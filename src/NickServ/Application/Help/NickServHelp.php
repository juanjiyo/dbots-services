<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\Help;

use Dbots\Irc\Application\Help\HelpCatalog;
use Dbots\Irc\Application\Help\HelpEntry;

final class NickServHelp
{
    public static function create(): HelpCatalog
    {
        return new HelpCatalog('NickServ', [
            new HelpEntry('REGISTER', 'REGISTER <contraseña> [email]', 'Registra tu nick actual.', '/msg NickServ REGISTER miClave correo@ejemplo.org', ['REGISTRA']),
            new HelpEntry('IDENTIFY', 'IDENTIFY <contraseña>', 'Comprueba tu contraseña. Para identificarte de verdad, reconecta con /NICK nick:contraseña.', '/msg NickServ IDENTIFY miClave', ['IDENTIFICA']),
            new HelpEntry('INFO', 'INFO <nick>', 'Muestra los datos de una cuenta.', '/msg NickServ INFO JuanJo'),
            new HelpEntry('STATUS', 'STATUS [nick]', 'Estado de la cuenta: alta, prohibición, suspensión y NOEXPIRE.', '/msg NickServ STATUS JuanJo', ['ESTADO']),
            new HelpEntry('SET', 'SET PASSWORD <actual> <nueva>', 'Cambia la contraseña de tu cuenta.', '/msg NickServ SET PASSWORD miClave otraClave'),
            new HelpEntry('DROP', 'DROP <contraseña>', 'Borra tu cuenta. Antes hay que soltar los canales que fundaste.', '/msg NickServ DROP miClave'),
            new HelpEntry('SUSPEND', 'SUSPEND <nick> [motivo]', 'Suspende una cuenta: podrá entrar, pero sin cuenta ni +r.', '/msg NickServ SUSPEND JuanJo abuso', operOnly: true),
            new HelpEntry('UNSUSPEND', 'UNSUSPEND <nick>', 'Levanta la suspensión.', '/msg NickServ UNSUSPEND JuanJo', operOnly: true),
            new HelpEntry('LISTSUSPEND', 'LISTSUSPEND', 'Lista las cuentas suspendidas.', '/msg NickServ LISTSUSPEND', operOnly: true),
            new HelpEntry('FORBID', 'FORBID <nick> [motivo]', 'Prohíbe un nick: no se podrá usar ni registrar.', '/msg NickServ FORBID JuanJo suplantación', operOnly: true),
            new HelpEntry('UNFORBID', 'UNFORBID <nick>', 'Levanta la prohibición.', '/msg NickServ UNFORBID JuanJo', operOnly: true),
            new HelpEntry('LISTFORBID', 'LISTFORBID', 'Lista los nicks prohibidos.', '/msg NickServ LISTFORBID', operOnly: true),
            new HelpEntry('SWHOIS', 'SWHOIS <nick> <texto>', 'Fija el SWHOIS que verá quien haga /WHOIS.', '/msg NickServ SWHOIS JuanJo Miembro del staff', operOnly: true),
            new HelpEntry('NOEXPIRE', 'NOEXPIRE <nick>', 'Activa o desactiva la exención de caducidad.', '/msg NickServ NOEXPIRE JuanJo', operOnly: true),
        ]);
    }
}
