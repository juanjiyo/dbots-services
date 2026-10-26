<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\GlobalServ;

use DateTimeImmutable;
use Dbots\Domain\Account\Account;
use Dbots\Domain\Account\Nick;
use Dbots\Domain\Staff\StaffRank;
use Dbots\GlobalServ\Adapter\In\Irc\GlobalServCommandHandler;
use Dbots\GlobalServ\Application\Help\GlobalServHelp;
use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\NetworkState;
use Dbots\Staff\Application\StaffAuthority;
use Dbots\Tests\Support\InMemoryAccountRepository;
use Dbots\Tests\Support\InMemoryStaffRankRepository;
use Dbots\Tests\Support\RecordingEnforcement;
use Dbots\Tests\Support\RecordingIrc;
use Dbots\Tests\Support\RecordingTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function explode;
use function implode;
use function preg_match;
use function str_contains;

final class GlobalServCommandHandlerTest extends TestCase
{
    private RecordingTransport $transport;
    private RecordingIrc $irc;
    private RecordingEnforcement $enforcement;
    private InMemoryAccountRepository $accounts;
    private InMemoryStaffRankRepository $ranks;
    private StaffAuthority $staff;
    private GlobalServCommandHandler $handler;
    private NetworkState $state;

    protected function setUp(): void
    {
        $this->transport = new RecordingTransport();
        $this->irc = new RecordingIrc();
        $this->enforcement = new RecordingEnforcement();
        $this->accounts = new InMemoryAccountRepository();
        $this->ranks = new InMemoryStaffRankRepository();
        $this->staff = new StaffAuthority($this->ranks);

        $this->handler = new GlobalServCommandHandler(
            $this->transport,
            $this->irc,
            $this->staff,
            $this->accounts,
            $this->enforcement,
            'GlobalServ',
            '003A00010',
            'bots.test',
            GlobalServHelp::create(),
        );

        $this->state = new NetworkState();
        $this->addUser('001AAAAAA', 'admin', StaffRank::Administrador);
        $this->state->introduceUser('001BBBBBB', 'user', null, 'i', 'user.example');
    }

    #[Test]
    public function anAdministratorCanSendAGlobalMessage(): void
    {
        $this->handler->handle($this->privmsg('GLOBAL Mensaje de prueba'), $this->state);

        self::assertSame([
            ':003A00010 PRIVMSG admin :' . "\x033[ Mensaje Global ]\x03",
            ':003A00010 PRIVMSG admin :Mensaje de prueba',
            ':003A00010 PRIVMSG user :' . "\x033[ Mensaje Global ]\x03",
            ':003A00010 PRIVMSG user :Mensaje de prueba',
        ], $this->transport->written);
        self::assertSame([
            '001AAAAAA: Mensaje global enviado correctamente.',
            '001AAAAAA: Número de Impactos: 2',
        ], $this->irc->replies);
    }

    #[Test]
    public function globalRequiresAMessage(): void
    {
        $this->handler->handle($this->privmsg('GLOBAL'), $this->state);

        self::assertSame([], $this->transport->written);
        self::assertSame(['001AAAAAA: Error de Sintaxis: GLOBAL <mensaje>'], $this->irc->replies);
    }

    #[Test]
    public function aNonAdminCannotSendGlobal(): void
    {
        $this->handler->handle($this->privmsg('GLOBAL Mensaje de prueba', '001BBBBBB'), $this->state);

        self::assertSame([], $this->transport->written);
        self::assertSame(['001BBBBBB: Permiso denegado.'], $this->irc->replies);
    }

    #[Test]
    public function nickBroadcastsFromATemporaryPseudoClient(): void
    {
        $this->handler->handle($this->privmsg('NICK Anuncio Mensaje temporal'), $this->state);

        $uidLine = $this->findUidLine();
        self::assertNotNull($uidLine);

        $tempUid = $this->extractTempUid($uidLine);
        self::assertNotNull($tempUid);

        self::assertSame([
            $uidLine,
            sprintf(':%s PRIVMSG admin :' . "\x033[ Mensaje Global ]\x03", $tempUid),
            sprintf(':%s PRIVMSG admin :Mensaje temporal', $tempUid),
            sprintf(':%s PRIVMSG user :' . "\x033[ Mensaje Global ]\x03", $tempUid),
            sprintf(':%s PRIVMSG user :Mensaje temporal', $tempUid),
            sprintf(':%s QUIT :', $tempUid),
        ], $this->transport->written);

        self::assertSame([
            '001AAAAAA: Mensaje global enviado correctamente.',
            '001AAAAAA: Número de Impactos: 2',
        ], $this->irc->replies);
    }

    #[Test]
    public function nickRejectsARegisteredNick(): void
    {
        $this->accounts->save(new Account(new Nick('Anuncio'), 'hash', null, new DateTimeImmutable()));

        $this->handler->handle($this->privmsg('NICK Anuncio Mensaje temporal'), $this->state);

        self::assertSame([], $this->transport->written);
        self::assertSame(['001AAAAAA: El nick Anuncio está registrado.'], $this->irc->replies);
    }

    #[Test]
    public function nickRejectsANickInUse(): void
    {
        $this->state->introduceUser('001CCCCCC', 'Anuncio', null, 'i', 'host.example');

        $this->handler->handle($this->privmsg('NICK Anuncio Mensaje temporal'), $this->state);

        self::assertSame([], $this->transport->written);
        self::assertSame(['001AAAAAA: El nick Anuncio actualmente está en uso.'], $this->irc->replies);
    }

    #[Test]
    public function chanSendsOnlyToChannelMembers(): void
    {
        $this->state->introduceUser('001CCCCCC', 'outsider', null, 'i', 'host.example');
        $this->state->joinChannel('#canal', ['001AAAAAA', '001BBBBBB']);

        $this->handler->handle($this->privmsg('CHAN #canal Hola canal'), $this->state);

        self::assertSame([
            ':003A00010 PRIVMSG admin :' . "\x033[ Mensaje Global ]\x03",
            ':003A00010 PRIVMSG admin :Hola canal',
            ':003A00010 PRIVMSG user :' . "\x033[ Mensaje Global ]\x03",
            ':003A00010 PRIVMSG user :Hola canal',
        ], $this->transport->written);
        self::assertSame([
            '001AAAAAA: Mensaje global enviado correctamente.',
            '001AAAAAA: Número de Impactos: 2',
        ], $this->irc->replies);
    }

    #[Test]
    public function chanRejectsChannelsWithoutHash(): void
    {
        $this->handler->handle($this->privmsg('CHAN nocanal Hola'), $this->state);

        self::assertSame([], $this->transport->written);
        self::assertSame(['001AAAAAA: El canal debe comenzar por #.'], $this->irc->replies);
    }

    #[Test]
    public function chanRejectsAnUnusedChannel(): void
    {
        $this->handler->handle($this->privmsg('CHAN #vacio Hola'), $this->state);

        self::assertSame([], $this->transport->written);
        self::assertSame(['001AAAAAA: El canal #vacio no está en uso en este momento.'], $this->irc->replies);
    }

    #[Test]
    public function joinForcesEveryUserIntoTheChannel(): void
    {
        $this->ranks->assign('admin', StaffRank::Root);
        $this->state->introduceUser('001CCCCCC', 'third', null, 'i', 'host.example');

        $this->handler->handle($this->privmsg('JOIN #reunion Aviso importante'), $this->state);

        self::assertSame(
            ['001AAAAAA->#reunion', '001BBBBBB->#reunion', '001CCCCCC->#reunion'],
            $this->enforcement->svsJoins,
        );
        self::assertCount(6, $this->transport->written);
        self::assertStringContainsString('PRIVMSG admin :' . "\x033[ Mensaje Global ]\x03", $this->transport->written[0]);
        self::assertSame([
            '001AAAAAA: Mensaje global enviado correctamente.',
            '001AAAAAA: Número de Impactos: 3',
        ], $this->irc->replies);
    }

    #[Test]
    public function joinRequiresRoot(): void
    {
        $this->handler->handle($this->privmsg('JOIN #reunion Aviso', '001AAAAAA'), $this->state);

        self::assertSame([], $this->enforcement->svsJoins);
        self::assertSame(['001AAAAAA: Permiso denegado.'], $this->irc->replies);
    }

    #[Test]
    public function joinRejectsChannelsWithoutHash(): void
    {
        $this->ranks->assign('admin', StaffRank::Root);

        $this->handler->handle($this->privmsg('JOIN noreunion Aviso'), $this->state);

        self::assertSame([], $this->enforcement->svsJoins);
        self::assertSame(['001AAAAAA: El canal debe comenzar por #.'], $this->irc->replies);
    }

    #[Test]
    public function preOperReachesPreOperAndAbove(): void
    {
        $this->addUser('001CCCCCC', 'preoper', StaffRank::PreOperador);
        $this->addUser('001DDDDDD', 'oper', StaffRank::Operador);

        $this->handler->handle($this->privmsg('PRE-OPER Aviso a staff'), $this->state);

        $nicks = $this->broadcastNicks();
        self::assertSame(['admin', 'preoper', 'oper'], $nicks);
        self::assertSame([
            '001AAAAAA: Mensaje global enviado correctamente.',
            '001AAAAAA: Número de Impactos: 3',
        ], $this->irc->replies);
    }

    #[Test]
    public function operReachesOperAndAbove(): void
    {
        $this->addUser('001CCCCCC', 'preoper', StaffRank::PreOperador);
        $this->addUser('001DDDDDD', 'oper', StaffRank::Operador);

        $this->handler->handle($this->privmsg('OPER Aviso a operadores'), $this->state);

        $nicks = $this->broadcastNicks();
        self::assertSame(['admin', 'oper'], $nicks);
    }

    #[Test]
    public function adminOnlyReachesAdministrators(): void
    {
        $this->ranks->assign('admin', StaffRank::Root);
        $this->addUser('001CCCCCC', 'preoper', StaffRank::PreOperador);
        $this->addUser('001DDDDDD', 'oper', StaffRank::Operador);
        $this->addUser('001EEEEEE', 'otheradmin', StaffRank::Administrador);

        $this->handler->handle($this->privmsg('ADMIN Aviso a administradores'), $this->state);

        $nicks = $this->broadcastNicks();
        self::assertSame(['otheradmin'], $nicks);
    }

    #[Test]
    public function coAdminReachesAdministratorsAndAbove(): void
    {
        $this->ranks->assign('admin', StaffRank::Root);
        $this->addUser('001CCCCCC', 'preoper', StaffRank::PreOperador);
        $this->addUser('001DDDDDD', 'oper', StaffRank::Operador);
        $this->addUser('001EEEEEE', 'otheradmin', StaffRank::Administrador);

        $this->handler->handle($this->privmsg('CO-ADMIN Aviso a co-administradores'), $this->state);

        $nicks = $this->broadcastNicks();
        self::assertSame(['admin', 'otheradmin'], $nicks);
    }

    #[Test]
    public function helpRendersTheCommandCatalog(): void
    {
        $this->handler->handle($this->privmsg('HELP'), $this->state);

        $output = implode("\n", $this->irc->replies);
        self::assertStringContainsString('Comandos de GlobalServ', $output);
        self::assertStringContainsString('GLOBAL', $output);
        self::assertStringContainsString('JOIN', $output);
    }

    #[Test]
    public function helpRendersOneCommand(): void
    {
        $this->handler->handle($this->privmsg('HELP NICK'), $this->state);

        $output = implode("\n", $this->irc->replies);
        self::assertStringContainsString('NICK', $output);
        self::assertStringContainsString('Nivel Administrador', $output);
    }

    #[Test]
    public function itIgnoresMessagesForAnotherService(): void
    {
        $this->handler->handle(new IrcMessage('001AAAAAA', 'PRIVMSG', ['CHaN'], 'GLOBAL Hola'), $this->state);

        self::assertSame([], $this->transport->written);
        self::assertSame([], $this->irc->replies);
    }

    #[Test]
    public function itRepliesToUnknownCommands(): void
    {
        $this->handler->handle($this->privmsg('UNKNOWN'), $this->state);

        self::assertSame(['001AAAAAA: Comando desconocido UNKNOWN. /msg GlobalServ AYUDA para ayuda.'], $this->irc->replies);
    }

    private function addUser(string $uid, string $nick, StaffRank $rank): void
    {
        $this->state->introduceUser($uid, $nick, $nick, 'r', 'host.example');
        $this->accounts->save(new Account(new Nick($nick), 'hash', null, new DateTimeImmutable()));
        $this->ranks->assign($nick, $rank);
    }

    private function privmsg(string $text, string $uid = '001AAAAAA'): IrcMessage
    {
        return new IrcMessage($uid, 'PRIVMSG', ['GlobalServ'], $text);
    }

    private function findUidLine(): ?string
    {
        foreach ($this->transport->written as $line) {
            if (str_contains($line, 'UID Anuncio')) {
                return $line;
            }
        }

        return null;
    }

    private function extractTempUid(string $uidLine): ?string
    {
        $parts = explode(' ', $uidLine);

        return $parts[7] ?? null;
    }

    /** @return list<string> */
    private function broadcastNicks(): array
    {
        $seen = [];

        foreach ($this->transport->written as $line) {
            if (1 === preg_match('/^:003A00010 PRIVMSG (\S+) :/', $line, $matches)) {
                $seen[$matches[1]] = true;
            }
        }

        return array_keys($seen);
    }
}
