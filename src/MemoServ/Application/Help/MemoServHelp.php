<?php

declare(strict_types=1);

namespace Dbots\MemoServ\Application\Help;

use Dbots\Irc\Application\Help\HelpCatalog;
use Dbots\Irc\Application\Help\HelpEntry;

final class MemoServHelp
{
    public static function create(): HelpCatalog
    {
        return new HelpCatalog('MemoServ', [
            new HelpEntry('SEND', 'SEND <nick> <mensaje>', 'Deja un memo a otra cuenta.', '/msg MemoServ SEND JuanJo nos vemos el sábado', ['ENVIA']),
            new HelpEntry('LIST', 'LIST', 'Lista tus memos, con número y si están leídos.', '/msg MemoServ LIST', ['LISTA']),
            new HelpEntry('READ', 'READ <número>', 'Lee un memo y lo marca como leído.', '/msg MemoServ READ 3', ['LEE']),
            new HelpEntry('DEL', 'DEL <número>', 'Borra un memo de tu lista.', '/msg MemoServ DEL 3', ['DELETE', 'BORRA']),
        ]);
    }
}
