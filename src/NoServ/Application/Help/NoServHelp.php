<?php

declare(strict_types=1);

namespace Dbots\NoServ\Application\Help;

use Dbots\Irc\Application\Help\HelpCatalog;
use Dbots\Irc\Application\Help\HelpEntry;

final class NoServHelp
{
    public static function create(): HelpCatalog
    {
        return new HelpCatalog('NoServ', [
            new HelpEntry('ALTA', 'ALTA', 'Da de alta tu nick en el servicio de noticias. Requiere nick identificado.', '/msg NoServ ALTA'),
            new HelpEntry('BAJA', 'BAJA', 'Da de baja tu nick del servicio de noticias. Requiere nick identificado.', '/msg NoServ BAJA'),
            new HelpEntry('EMAIL', 'EMAIL <mensaje>', 'Envía un e-mail a todos los usuarios dados de alta. Nivel Administrador.', '/msg NoServ EMAIL Aviso importante', operOnly: true),
            new HelpEntry('MOTD', 'MOTD ADD|DEL|LIST [mensaje]', 'Gestiona noticias para usuarios. Nivel Administrador.', '/msg NoServ MOTD ADD Nueva funcionalidad', operOnly: true),
            new HelpEntry('NICK', 'NICK ADD|DEL|LIST <nick> [mensaje]', 'Gestiona nicks temporales con mensaje de entrada. Nivel Administrador.', '/msg NoServ NICK ADD Info Bienvenidos a la red', operOnly: true),
            new HelpEntry('REPRESENTANTES', 'REPRESENTANTES ADD|DEL|LIST [mensaje]', 'Gestiona noticias para el staff. Nivel Administrador.', '/msg NoServ REPRESENTANTES ADD Reunión de staff', operOnly: true),
            new HelpEntry('NOTICIAS', 'NOTICIAS', 'Muestra la noticia externa configurada. Nivel Administrador.', '/msg NoServ NOTICIAS', operOnly: true),
            new HelpEntry('SERVIDOR', 'SERVIDOR <servidor> [mensaje]', 'Gestiona mensajes de entrada por servidor. Nivel Administrador.', '/msg NoServ SERVIDOR irc.sitiochat.es Bienvenidos', operOnly: true),
        ]);
    }
}
