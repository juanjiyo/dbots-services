<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\Help;

use Dbots\ChanServ\Application\Help\ChanServHelp;
use Dbots\Irc\Application\Help\HelpCatalog;
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
            self::assertStringContainsString('Comandos de', $lines[0], $name);
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

        self::assertStringContainsString('Sintaxis: REGISTER', $lines[1]);
        self::assertStringContainsString('Ejemplo:', implode(' ', $lines));
    }

    #[Test]
    public function aliasesResolveToTheirCommand(): void
    {
        $lines = NickServHelp::create()->render('registra', false);

        self::assertStringContainsString('REGISTER', $lines[0]);
        self::assertStringContainsString('REGISTRA', implode(' ', $lines));
    }

    #[Test]
    public function anUnknownCommandSaysSo(): void
    {
        $lines = ChanServHelp::create()->render('INVENTADO', false);

        self::assertStringContainsString('No hay ayuda para INVENTADO', $lines[0]);
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
