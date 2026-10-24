<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\Help;

use Dbots\ChanServ\Application\Help\ChanServHelp;
use Dbots\Irc\Application\Help\HelpCatalog;
use Dbots\Irc\Application\Help\IrcFormat;
use Dbots\MemoServ\Application\Help\MemoServHelp;
use Dbots\NickServ\Application\Help\NickServHelp;
use Dbots\OperServ\Application\Help\OperServHelp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HelpCatalogTest extends TestCase
{
    /** @return iterable<string, array{HelpCatalog}> */
    public static function catalogs(): iterable
    {
        yield 'nickserv' => [NickServHelp::create()];
        yield 'chanserv' => [ChanServHelp::create()];
        yield 'memoserv' => [MemoServHelp::create()];
        yield 'operserv' => [OperServHelp::create()];
    }

    #[Test]
    public function everyCatalogRendersAnIndex(): void
    {
        foreach (self::catalogs() as $name => [$catalog]) {
            $lines = $catalog->render('', false);
            self::assertNotEmpty($lines, $name);
            self::assertStringContainsString('Comandos de', IrcFormat::strip($lines[0]), $name);
        }
    }

    #[Test]
    public function anOperSeesMoreCommandsThanAUser(): void
    {
        $catalog = NickServHelp::create();

        self::assertGreaterThan(
            count($catalog->commands(false)),
            count($catalog->commands(true)),
        );
    }

    #[Test]
    public function anOperOnlyPageIsHiddenFromUsers(): void
    {
        $lines = OperServHelp::create()->render('KILL', false);

        self::assertStringContainsString('operadores', $lines[0]);
    }

    #[Test]
    public function aPageShowsSyntaxAndExample(): void
    {
        $lines = NickServHelp::create()->render('REGISTER', false);
        $plain = IrcFormat::strip(implode(' ', $lines));

        self::assertStringContainsString('Sintaxis: REGISTER', IrcFormat::strip($lines[1]));
        self::assertStringContainsString('Ejemplo:', $plain);
    }

    #[Test]
    public function aliasesResolveToTheirCommand(): void
    {
        $lines = NickServHelp::create()->render('registra', false);
        $plain = IrcFormat::strip(implode(' ', $lines));

        self::assertStringContainsString('REGISTER', $plain);
        self::assertStringContainsString('REGISTRA', $plain);
    }

    #[Test]
    public function anUnknownCommandSaysSo(): void
    {
        $lines = ChanServHelp::create()->render('INVENTADO', false);

        self::assertStringContainsString('No hay ayuda para INVENTADO', $lines[0]);
    }

    #[Test]
    public function theIndexUsesTheOriginalPalette(): void
    {
        $lines = NickServHelp::create()->render('', false);

        // 3 = header, 12 = command names, exactly as the mIRC help used them.
        self::assertStringStartsWith("\x03" . '03', $lines[0]);
        self::assertStringContainsString("\x0312REGISTER\x03", $lines[1]);
    }

    #[Test]
    public function everyDocumentedCommandExistsInItsHandler(): void
    {
        $sources = [
            [NickServHelp::create(), 'src/NickServ/Adapter/In/Irc/NickServCommandHandler.php'],
            [ChanServHelp::create(), 'src/ChanServ/Adapter/In/Irc/ChanServCommandHandler.php'],
            [MemoServHelp::create(), 'src/MemoServ/Adapter/In/Irc/MemoServCommandHandler.php'],
            [OperServHelp::create(), 'src/OperServ/Adapter/In/Irc/OperServCommandHandler.php'],
        ];

        foreach ($sources as [$catalog, $file]) {
            $source = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $file);

            foreach ($catalog->commands(true) as $entry) {
                self::assertStringContainsString(
                    "'" . $entry->command . "'",
                    $source,
                    sprintf('%s documents %s but the handler does not dispatch it', $file, $entry->command),
                );
            }
        }
    }
}
