<?php

declare(strict_types=1);

namespace Dbots\MemoServ\Application\Help;

use Dbots\Irc\Application\Help\HelpCatalog;
use Dbots\Irc\Application\Help\HelpEntry;

final class MemoServHelp
{
    public static function create(): HelpCatalog
    {
        return new HelpCatalog('MeMo', [
            new HelpEntry('SEND', 'SEND <nick> <mensaje>', 'Deja un memo a otra cuenta.', '/msg MeMo SEND JuanJo nos vemos el sábado', ['ENVIA']),
            new HelpEntry('LIST', 'LIST', 'Lista tus memos, con número y si están leídos.', '/msg MeMo LIST', ['LISTA']),
            new HelpEntry('READ', 'READ <número>', 'Lee un memo y lo marca como leído.', '/msg MeMo READ 3', ['LEE']),
            new HelpEntry('DEL', 'DEL <número>', 'Borra un memo de tu lista.', '/msg MeMo DEL 3', ['DELETE', 'BORRA']),
        ]);
    }
}
