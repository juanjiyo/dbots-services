<?php

declare(strict_types=1);

namespace Dbots\HeLPServ\Application\Help;

use Dbots\Irc\Application\Help\IrcFormat;

use function sprintf;

/**
 * HeLP serves prose, not a command table, so its texts live here instead of in
 * a HelpCatalog. The wording is the one the original `he.mrc` sends.
 */
final class NetworkHelp
{
    /** @return list<string> */
    public static function index(string $serviceNick): array
    {
        return [
            IrcFormat::paint(IrcFormat::HEADER, sprintf('[ Ayuda de %s ]', $serviceNick)),
            '',
            IrcFormat::paint(IrcFormat::COMMAND, $serviceNick).' bot de ayuda global de la red.',
            'Teclea las palabras en azul para más información sobre el tema.',
            '',
            IrcFormat::paint(IrcFormat::LABEL, 'Información básica de la red:'),
            '',
            IrcFormat::paint(IrcFormat::COMMAND, 'UMODES').' Muestra los modos de usuario disponibles.',
            IrcFormat::paint(IrcFormat::COMMAND, 'CMODES').' Muestra los modos de canal disponibles.',
            IrcFormat::paint(IrcFormat::COMMAND, 'BOTS').' Muestra información sobre los bots de la red.',
            IrcFormat::paint(IrcFormat::COMMAND, 'CREDITOS').' Muestra los créditos de los bots.',
            '',
            IrcFormat::paint(IrcFormat::LABEL, 'Algunos servicios básicos de la red:'),
            '',
            IrcFormat::paint(IrcFormat::COMMAND, 'NICK').' Instrucciones de cómo registrar un nick.',
            IrcFormat::paint(IrcFormat::COMMAND, 'CANAL').' Instrucciones de cómo registrar un canal.',
            '',
            'Para cualquier tipo de información a mayores de la que aparece en',
            'este bot consulta con un operador (@) en #opers_help o en la web oficial de la red.',
        ];
    }

    /** @return list<string> */
    public static function umodes(): array
    {
        $lines = ['Modos disponibles para usuarios:', ''];

        $lines = [...$lines, ...self::describe([
            'r' => 'Nick registrado y protegido.',
            'R' => 'Solo admites privados de gente que tenga el nick registrado y migrado.',
            'x' => 'Protección de IP contra nukes. (ip virtual)',
            'i' => 'Modo invisible. (No aparece utilizando /who)',
            's' => 'Recibir noticias del servidor.',
            'w' => 'Recibir wallops.',
            'g' => 'Recibir mensajes del servidor si estás en modo DEBUG.',
            'd' => 'Se ignora el flujo de los canales.',
            'V' => 'Cliente WebTV.',
            'v' => 'Rechaza un DCC de un archivo infectado con un virus.',
            'G' => 'Censura palabras prohibidas en privados.',
            'D' => 'Ignora privados.',
            'T' => 'No recibe CTCP\'s.',
            'z' => 'Usuario conectado por SSL.',
            't' => 'Usuario con vhost configurado (/vhost).',
            'p' => 'Esconde los canales al usuario que te hace un /whois.',
            'S' => 'Nick SUSPENDIDO.',
        ])];

        $lines[] = '';
        $lines[] = 'Modos sólo para Pre-Opers, Opers, Co-Admins y Admins:';
        $lines[] = '';

        $lines = [...$lines, ...self::describe([
            'h' => 'Representante de la red.',
            'o' => 'IRCop de la red.',
            'O' => 'IRCop de la red (2º grado).',
            'X' => 'Ver las ips REALES a usuarios que tienen +x.',
            'k' => 'Modo Channel Service, no pueden ser deopeados o kickeados de un canal.',
            'a' => 'ADMINistrador de services.',
            'A' => 'ADMINistrador de servidor.',
            'N' => 'ADMINistrador de la red.',
            'C' => 'CO-ADMINistrador.',
            'W' => 'Te avisa si te hacen un /whois.',
            'q' => 'Solo U:Lines (services) pueden expulsarlo.',
            'H' => 'Esconde el estatus de IRCop.',
        ])];

        $lines[] = '';
        $lines[] = 'Modos de usuarios ESPECIALES:';
        $lines[] = '';

        $lines = [...$lines, ...self::describe([
            'B' => 'Indica que es un roBOT oficial de la red.',
        ])];

        return [...$lines,
            '',
            sprintf('Para cambiar los modos de usuario, escriba %s.', IrcFormat::paint(IrcFormat::COMMAND, '/mode nick {+/-}modos')),
            sprintf('La %s es para agregar modos y la %s para quitar modos.', IrcFormat::paint(IrcFormat::COMMAND, '+'), IrcFormat::paint(IrcFormat::COMMAND, '-')),
            sprintf('El modo %s de nick registrado no se puede poner ni quitar a voluntad.', IrcFormat::paint(IrcFormat::COMMAND, 'r')),
        ];
    }

    /** @return list<string> */
    public static function cmodes(): array
    {
        $lines = ['En esta red, los canales pueden tener los siguientes modos:', ''];

        $lines = [...$lines, ...self::describe([
            'r' => 'Canal registrado.',
            'p' => 'Canal privado (no sale en la lista de canales /list).',
            's' => 'Canal secreto (no sale en la lista de canales /list).',
            'i' => 'Canal de sólo invitados.',
            'k' => 'Canal protegido con clave, para entrar necesita la contraseña.',
            'l' => 'Con un número, numero máximo de usuarios que pueden entrar.',
            'R' => 'Solo pueden entrar los usuarios con nicks registrados con +r.',
            'M' => 'Solo pueden hablar los usuarios con nicks registrados con +r.',
            'm' => 'Canal moderado sólo pueden hablar los usuarios con +q, +a, +o, +h y +v.',
            't' => 'Solo los usuarios con +q, +a, +o y +h pueden cambiar el topic.',
            'n' => 'Solo los usuarios que están dentro pueden hablar.',
            'c' => 'Bloquea una línea que contenga colores.',
            'u' => 'Solo los operadores +q, +a y +o pueden hacer /names y /who al canal.',
            'C' => 'Bloquea los CTCPs.',
            'G' => 'Censura palabras prohibidas.',
            'N' => 'Los usuarios no pueden cambiar el nick mientras estén en el canal.',
            'S' => 'Elimina los colores de una línea.',
            'T' => 'No permite NOTICEs en el canal.',
            'V' => 'No permite INVITEs en el canal.',
            'O' => 'Solo IRCops pueden entrar al canal.',
            'A' => 'Solo ADMINistradores pueden entrar al canal.',
            'L' => 'Fija el canal al que entraran los usuarios al pasarse el modo +l.',
            'Q' => 'Solo U:Lines (services) pueden echar (kickear) a los usuarios del canal.',
            'K' => '/knock no permitidos en el canal.',
            'z' => 'Solo usuarios conectados por SSL pueden entrar al canal.',
        ])];

        $lines[] = '';
        $lines[] = 'Modos de usuario dentro del canal:';
        $lines[] = '';

        $lines = [...$lines, ...self::describe([
            'q [nick]' => 'Status de fundador del canal.',
            'a [nick]' => 'Status de sucesor del canal.',
            'o [nick]' => 'Status de operador del canal.',
            'h [nick]' => 'Status de semi-operador del canal.',
            'v [nick]' => 'Status de moderador del canal.',
            'b [nick!user@host]' => 'Prohibe la entrada (Ban) a la mascara <nick!ident@host>.',
            'e [nick!user@host]' => 'Exceptúa un (Ban) a la mascara <nick!ident@host>.',
            'f [lineas:segundos]' => 'Fija protecciones en el canal mediante el servidor.',
        ])];

        return [...$lines,
            '',
            sprintf('Para cambiar los modos de canal en un canal, escriba %s.', IrcFormat::paint(IrcFormat::COMMAND, '/mode canal {+/-}modos')),
            sprintf('La %s es para agregar modos y la %s para quitar modos.', IrcFormat::paint(IrcFormat::COMMAND, '+'), IrcFormat::paint(IrcFormat::COMMAND, '-')),
        ];
    }

    /** @param array<string, string> $bots nick => description
     *  @return list<string> */
    public static function bots(array $bots): array
    {
        $lines = ['A continuación se muestra una lista de los bots que funcionan en esta red:', ''];

        foreach ($bots as $nick => $description) {
            $lines[] = IrcFormat::paint(IrcFormat::COMMAND, $nick).' '.$description;
        }

        return [...$lines,
            '',
            sprintf('Para ver la ayuda de cada bot, escriba %s.', IrcFormat::paint(IrcFormat::COMMAND, '/msg BOT AYUDA')),
        ];
    }

    /** @return list<string> */
    public static function nick(string $serviceNick, string $cregNick): array
    {
        return [
            sprintf('¿Cómo se registra un %s?', IrcFormat::paint(IrcFormat::COMMAND, 'nick')),
            '',
            sprintf('Debes poner %s', IrcFormat::paint(IrcFormat::COMMAND, sprintf('/msg %s REGISTRA <contraseña>', $serviceNick))),
            '',
            sprintf('%s: No cambies la palabra %s ya que es el nombre del bot de', IrcFormat::paint(IrcFormat::COMMAND, 'NOTA'), $serviceNick),
            'registro y mantenimiento de nicks. El nick que se registrará',
            'será el que tengas puesto.',
            '',
            'Después de registrar el nick, identifícate cada vez que entres',
            sprintf('con %s', IrcFormat::paint(IrcFormat::COMMAND, sprintf('/msg %s IDENTIFICA <contraseña>', $serviceNick))),
            '',
            sprintf('Para registrar un canal, mira la ayuda de %s.', IrcFormat::paint(IrcFormat::COMMAND, 'CANAL')),
            sprintf('%s no interviene en el registro de canales: eso es cosa de %s.', $serviceNick, $cregNick),
        ];
    }

    /** @return list<string> */
    public static function canal(string $cregNick, string $chanNick): array
    {
        return [
            sprintf('¿Cómo se registra un %s?', IrcFormat::paint(IrcFormat::COMMAND, 'canal')),
            '',
            'Primero hay que hacer una petición de pre-registro del canal,',
            'necesitas tener el nick registrado y tener @ en el canal para hacer la petición.',
            sprintf('El pre-registro se solicita al bot %s, la sintaxis es la siguiente:', IrcFormat::paint(IrcFormat::COMMAND, $cregNick)),
            '',
            sprintf('Sintaxis: %s', IrcFormat::paint(IrcFormat::COMMAND, sprintf('/msg %s REGISTRA <canal> <contraseña> <descripción>', $cregNick))),
            '',
            sprintf('%s El canal que quieres registrar', IrcFormat::paint(IrcFormat::COMMAND, '<canal>')),
            sprintf('%s Una contraseña para identificarte como fundador', IrcFormat::paint(IrcFormat::COMMAND, '<contraseña>')),
            sprintf('%s Una descripción sobre la temática del canal', IrcFormat::paint(IrcFormat::COMMAND, '<descripción>')),
            '',
            'Una vez revisada la petición, la comisión de canales de la red',
            'aprueba el canal y te avisa por memo.',
            '',
            sprintf('Con la contraseña del canal te identificas como fundador con %s', IrcFormat::paint(IrcFormat::COMMAND, sprintf('/msg %s IDENTIFY <canal> <contraseña>', $chanNick))),
            sprintf('aunque no estés identificado con tu nick, y así puedes usar los mandos de %s.', $chanNick),
        ];
    }

    /** @return list<string> */
    public static function creditos(): array
    {
        return [
            IrcFormat::paint(IrcFormat::COMMAND, 'DBots'),
            '',
            'Autores:',
            sprintf('- Hasta la versión %s %s (David Abuín Fontán)', IrcFormat::paint(IrcFormat::COMMAND, '2'), IrcFormat::paint(IrcFormat::COMMAND, 'Davidlig')),
            sprintf('- Desde la versión %s %s (Tomás Castro Prada)', IrcFormat::paint(IrcFormat::COMMAND, '3'), IrcFormat::paint(IrcFormat::COMMAND, 'Mew')),
            '',
            'URL: '.IrcFormat::paint(IrcFormat::COMMAND, 'http://www.dbots.es'),
            'E-Mail: '.IrcFormat::paint(IrcFormat::COMMAND, 'contacto@dbots.es'),
            '',
            'Si encuentras algún bug pásate por el servidor',
            sprintf('%s o bien envía un e-mail', IrcFormat::paint(IrcFormat::COMMAND, 'irc.dbots.es')),
            sprintf('a la dirección de correo %s', IrcFormat::paint(IrcFormat::COMMAND, 'bugs@dbots.es')),
        ];
    }

    /**
     * @param array<string, string> $entries
     * @return list<string>
     */
    private static function describe(array $entries): array
    {
        $lines = [];

        foreach ($entries as $token => $description) {
            $lines[] = IrcFormat::paint(IrcFormat::COMMAND, $token).' '.$description;
        }

        return $lines;
    }
}
