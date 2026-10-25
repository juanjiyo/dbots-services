<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\CenTeRServ;

use DateTimeImmutable;
use Dbots\CenTeRServ\Adapter\In\Irc\CenTeRServCommandHandler;
use Dbots\CenTeRServ\Application\Help\CenTeRServHelp;
use Dbots\Domain\Account\Account;
use Dbots\Domain\Account\Nick;
use Dbots\Domain\Staff\StaffRank;
use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Adapter\Out\Irc\ServiceUidFormatter;
use Dbots\Irc\Application\AccountResolver;
use Dbots\Irc\Application\NetworkState;
use Dbots\Module\ModuleRegistry;
use Dbots\Module\ModuleRuntime;
use Dbots\Staff\Application\StaffAuthority;
use Dbots\Tests\Support\InMemoryAccountRepository;
use Dbots\Tests\Support\InMemoryModuleStateRepository;
use Dbots\Tests\Support\InMemoryStaffRankRepository;
use Dbots\Tests\Support\RecordingEnforcement;
use Dbots\Tests\Support\RecordingIrc;
use Dbots\Tests\Support\RecordingTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CenTeRServCommandHandlerTest extends TestCase
{
    private InMemoryAccountRepository $accounts;
    private InMemoryStaffRankRepository $ranks;
    private InMemoryModuleStateRepository $moduleState;
    private RecordingIrc $irc;
    private RecordingTransport $transport;
    private RecordingEnforcement $enforcement;
    private CenTeRServCommandHandler $handler;
    private NetworkState $state;

    protected function setUp(): void
    {
        $this->accounts = new InMemoryAccountRepository();
        foreach (['root', 'ana', 'bob'] as $nick) {
            $this->accounts->save(new Account(new Nick($nick), 'hash', null, new DateTimeImmutable()));
        }

        $this->ranks = new InMemoryStaffRankRepository();
        $this->moduleState = new InMemoryModuleStateRepository();
        $this->irc = new RecordingIrc();
        $this->transport = new RecordingTransport();
        $this->enforcement = new RecordingEnforcement();

        $registry = new ModuleRegistry($this->moduleState, __DIR__.'/../../../modules');
        $modules = new ModuleRuntime(
            $registry,
            $this->transport,
            new ServiceUidFormatter('003'),
            $this->enforcement,
            '003',
            'bots.sitiochat.es',
            '#debug',
        );

        $this->handler = new CenTeRServCommandHandler(
            $this->irc,
            new StaffAuthority($this->ranks),
            $this->ranks,
            $this->accounts,
            $modules,
            new AccountResolver($this->accounts),
            'CeNTeR',
            '003A00008',
            CenTeRServHelp::create(),
            'claveRaiz',
        );

        $this->state = new NetworkState();
        $this->state->introduceUser('001AAAAAA', 'ana', 'ana', 'r');
    }

    private function as(string $account, StaffRank $rank): void
    {
        $this->ranks->assign($account, $rank);
    }

    #[Test]
    public function anOperatorCannotUseTheControlService(): void
    {
        $this->as('ana', StaffRank::Operador);

        $this->handler->handle($this->privmsg('OPER ADD bob'), $this->state);

        self::assertSame(['001AAAAAA: Permiso denegado.'], $this->irc->replies);
        self::assertNull($this->ranks->rankOf('bob'));
    }

    #[Test]
    public function anAdministratorHandsOutOperatorRanks(): void
    {
        $this->as('ana', StaffRank::Administrador);

        $this->handler->handle($this->privmsg('OPER ADD bob'), $this->state);
        $this->handler->handle($this->privmsg('OPER ADD bob'), $this->state);
        $this->handler->handle($this->privmsg('OPER DEL bob'), $this->state);

        self::assertSame([
            '001AAAAAA: bob añadido como Operador de la red.',
            '001AAAAAA: bob ya es un representante de la red.',
            '001AAAAAA: bob borrado de Operador de la red.',
        ], $this->irc->replies);
    }

    #[Test]
    public function onlyRootHandsOutAdministrator(): void
    {
        $this->as('ana', StaffRank::Administrador);

        $this->handler->handle($this->privmsg('ADMIN ADD bob'), $this->state);
        self::assertSame(['001AAAAAA: Permiso denegado.'], $this->irc->replies);

        $this->as('ana', StaffRank::Root);
        $this->irc->replies = [];
        $this->handler->handle($this->privmsg('ADMIN ADD bob'), $this->state);

        self::assertSame(['001AAAAAA: bob añadido como Administrador de la red.'], $this->irc->replies);
    }

    #[Test]
    public function theRootPasswordOpensTheFirstSession(): void
    {
        $this->handler->handle($this->privmsg('ROOT claveRaiz'), $this->state);
        $this->handler->handle($this->privmsg('ADMIN ADD bob'), $this->state);

        self::assertSame([
            '001AAAAAA: Ahora estás identificado como Root.',
            '001AAAAAA: bob añadido como Administrador de la red.',
        ], $this->irc->replies);
    }

    #[Test]
    public function aWrongRootPasswordIsRefused(): void
    {
        $this->handler->handle($this->privmsg('ROOT otraCosa'), $this->state);

        self::assertSame(['001AAAAAA: Contraseña incorrecta.'], $this->irc->replies);
    }

    #[Test]
    public function remoperStripsWhateverRankWasThere(): void
    {
        $this->as('ana', StaffRank::Root);
        $this->as('bob', StaffRank::PreOperador);

        $this->handler->handle($this->privmsg('REMOPER bob'), $this->state);
        $this->handler->handle($this->privmsg('REMOPER bob'), $this->state);

        self::assertSame([
            '001AAAAAA: Quitados los rangos de la red a bob.',
            '001AAAAAA: bob no tiene ningún rango de la red.',
        ], $this->irc->replies);
    }

    #[Test]
    public function itRefusesAnUnregisteredNick(): void
    {
        $this->as('ana', StaffRank::Administrador);

        $this->handler->handle($this->privmsg('OPER ADD fantasma'), $this->state);

        self::assertSame(['001AAAAAA: El nick fantasma no está registrado.'], $this->irc->replies);
    }

    #[Test]
    public function modulesAreListedAndToggledByRootOnly(): void
    {
        $this->as('ana', StaffRank::Root);

        $this->handler->handle($this->privmsg('MODULO LIST'), $this->state);
        $this->handler->handle($this->privmsg('MODULO UNLOAD Ping'), $this->state);
        $this->handler->handle($this->privmsg('MODULO LOAD NoExiste'), $this->state);

        self::assertStringContainsString('Ping: cargado', implode("\n", $this->irc->replies));
        self::assertContains('001AAAAAA: Módulo Ping descargado.', $this->irc->replies);
        self::assertContains('001AAAAAA: El módulo NoExiste no existe.', $this->irc->replies);
        self::assertFalse($this->moduleState->isEnabled('Ping', true));
    }

    #[Test]
    public function moduleLoadAppliesImmediately(): void
    {
        $this->moduleState->setEnabled('Ping', false);
        $this->as('ana', StaffRank::Root);

        $this->handler->handle($this->privmsg('MODULO LOAD Ping'), $this->state);

        self::assertTrue($this->moduleState->isEnabled('Ping', false));
        self::assertContains('001AAAAAA: Módulo Ping cargado.', $this->irc->replies);
        self::assertStringContainsString('UID PiNG 0 ', implode("\n", $this->transport->written));
        self::assertStringContainsString('JOIN #debug', implode("\n", $this->transport->written));
    }

    #[Test]
    public function moduleUnloadQuitsThePseudoClient(): void
    {
        $this->as('ana', StaffRank::Root);

        $this->handler->handle($this->privmsg('MODULO LOAD Ping'), $this->state);
        $this->irc->replies = [];
        $this->transport->written = [];

        $this->handler->handle($this->privmsg('MODULO UNLOAD Ping'), $this->state);

        self::assertContains('001AAAAAA: Módulo Ping descargado.', $this->irc->replies);
        self::assertStringContainsString('QUIT :Módulo descargado', implode("\n", $this->transport->written));
    }

    #[Test]
    public function itIgnoresMessagesForAnotherService(): void
    {
        $this->handler->handle(new IrcMessage('001AAAAAA', 'PRIVMSG', ['CHaN'], 'HELP'), $this->state);

        self::assertSame([], $this->irc->replies);
    }

    private function privmsg(string $text, string $uid = '001AAAAAA'): IrcMessage
    {
        return new IrcMessage($uid, 'PRIVMSG', ['CeNTeR'], $text);
    }
}
