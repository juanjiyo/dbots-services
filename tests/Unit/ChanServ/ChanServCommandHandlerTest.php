<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\ChanServ;

use Dbots\ChanServ\Adapter\In\Irc\ChanServCommandHandler;
use Dbots\ChanServ\Application\DropChannel\DropChannelHandler;
use Dbots\ChanServ\Application\Help\ChanServHelp;
use Dbots\Domain\Account\Account;
use Dbots\Domain\Account\Nick;
use Dbots\Domain\Account\Password;
use Dbots\Domain\Channel\ChannelName;
use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\AccountResolver;
use Dbots\Irc\Application\NetworkState;
use Dbots\Tests\Support\InMemoryAccountRepository;
use Dbots\Tests\Support\InMemoryChannelAccessRepository;
use Dbots\Tests\Support\InMemoryChannelAkickRepository;
use Dbots\Tests\Support\InMemoryChannelForbidRepository;
use Dbots\Tests\Support\InMemoryChannelLevelRepository;
use Dbots\Tests\Support\InMemoryChannelOptionRepository;
use Dbots\Tests\Support\InMemoryChannelRepository;
use Dbots\Tests\Support\RecordingChannelActions;
use Dbots\Tests\Support\RecordingChannelProjection;
use Dbots\Tests\Support\RecordingIrc;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChanServCommandHandlerTest extends TestCase
{
    private InMemoryChannelRepository $channels;
    private InMemoryAccountRepository $accounts;
    private RecordingIrc $irc;
    private RecordingChannelProjection $projection;
    private RecordingChannelActions $actions;
    private ChanServCommandHandler $handler;
    private NetworkState $state;

    protected function setUp(): void
    {
        $this->channels = new InMemoryChannelRepository();
        $this->accounts = new InMemoryAccountRepository();
        $this->accounts->save(new Account(new Nick('alice'), 'hash', null, new \DateTimeImmutable()));
        $this->accounts->save(new Account(new Nick('bob'), 'hash', null, new \DateTimeImmutable()));
        $this->irc = new RecordingIrc();
        $this->projection = new RecordingChannelProjection();
        $this->actions = new RecordingChannelActions();
        $this->handler = new ChanServCommandHandler(
            new DropChannelHandler($this->channels, new InMemoryChannelAccessRepository()),
            $this->channels,
            new InMemoryChannelAccessRepository(),
            new InMemoryChannelLevelRepository(),
            $this->actions,
            new InMemoryChannelAkickRepository(),
            new InMemoryChannelForbidRepository(),
            new InMemoryChannelOptionRepository(),
            $this->accounts,
            new \Dbots\Staff\Application\StaffAuthority(new \Dbots\Tests\Support\InMemoryStaffRankRepository()),
            $this->projection,
            new AccountResolver($this->accounts),
            $this->irc,
            'CHaN',
            '003A00002',
            ChanServHelp::create(),
        );
        $this->state = new NetworkState();
        $this->state->introduceUser('001AAAAAA', 'alice', null, 'r');
    }

    #[Test]
    public function registrationIsRequestedThroughCRegNotChanServ(): void
    {
        $this->handler->handle($this->privmsg('register #SitioChat'), $this->state);

        self::assertNull($this->channels->find(new \Dbots\Domain\Channel\ChannelName('#sitiochat')));
        self::assertStringContainsString('CReG', $this->irc->replies[0]);
    }

    #[Test]
    public function anAnonymousSenderIsSentToCRegToo(): void
    {
        $state = new NetworkState();
        $state->introduceUser('001BBBBBB', 'bob');

        $this->handler->handle($this->privmsg('register #Test', '001BBBBBB'), $state);

        self::assertSame([], $this->channels->all());
        self::assertStringContainsString('CReG', $this->irc->replies[0]);
    }

    #[Test]
    public function registeringTwiceStillPointsAtCReg(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->handle($this->privmsg('register #test'), $this->state);

        self::assertStringContainsString('CReG', $this->irc->replies[0]);
        self::assertSame([], $this->projection->registered);
    }



    #[Test]
    public function theFounderCanDropTheirChannel(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->handle($this->privmsg('drop #test'), $this->state);

        self::assertNull($this->channels->find(new \Dbots\Domain\Channel\ChannelName('#test')));
        self::assertSame(['#test'], $this->projection->dropped);
        self::assertSame(['001AAAAAA: #test has been dropped.'], $this->irc->replies);
    }

    #[Test]
    public function aStrangerCannotDropAChannel(): void
    {
        $this->channels->seed('#Test', 'alice');
        $state = new NetworkState();
        $state->introduceUser('001BBBBBB', 'bob', null, 'r');

        $this->handler->handle($this->privmsg('drop #test', '001BBBBBB'), $state);

        self::assertNotNull($this->channels->find(new \Dbots\Domain\Channel\ChannelName('#test')));
        self::assertSame(['001BBBBBB: Only the founder can drop a channel.'], $this->irc->replies);
    }

    #[Test]
    public function itReportsChannelInformation(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->handle($this->privmsg('info #test'), $this->state);

        self::assertCount(1, $this->irc->replies);
        self::assertStringContainsString('#test is registered to alice', $this->irc->replies[0]);
    }

    #[Test]
    public function theFounderCanGrantAndRevokeAccess(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->handle($this->privmsg('access add #test bob 400'), $this->state);
        self::assertSame(
            ['001AAAAAA: bob agregado a la lista de acceso de #test con nivel 400.'],
            $this->irc->replies,
        );

        $this->handler->handle($this->privmsg('access list #test'), $this->state);
        self::assertContains('001AAAAAA: Nick: bob - Nivel: 400', $this->irc->replies);
        self::assertSame('001AAAAAA: Fin de la lista.', end($this->irc->replies));

        $this->handler->handle($this->privmsg('access del #test bob'), $this->state);
        self::assertSame('001AAAAAA: bob borrado de la lista de acceso de #test.', end($this->irc->replies));
    }

    #[Test]
    public function aStrangerCannotGrantAccess(): void
    {
        $this->channels->seed('#Test', 'alice');
        $state = new NetworkState();
        $state->introduceUser('001BBBBBB', 'bob', null, 'r');

        $this->handler->handle($this->privmsg('access add #test bob 400', '001BBBBBB'), $state);

        self::assertSame(['001BBBBBB: Permiso denegado.'], $this->irc->replies);
    }

    #[Test]
    public function theChannelPasswordIdentifiesTheFounder(): void
    {
        $this->channels->seed('#Test', 'alice', Password::fromPlain('claveCanal123')->hash());
        $state = new NetworkState();
        $state->introduceUser('001BBBBBB', 'bob', null, '');

        $this->handler->handle($this->privmsg('identify #test claveCanal123', '001BBBBBB'), $state);
        $this->handler->handle($this->privmsg('topic #test hola', '001BBBBBB'), $state);

        self::assertSame(['001BBBBBB: Estás identificado como fundador de #test.'], [$this->irc->replies[0]]);
        self::assertSame(['#test'], $this->projection->topics);
    }

    #[Test]
    public function aWrongChannelPasswordDoesNotIdentify(): void
    {
        $this->channels->seed('#Test', 'alice', Password::fromPlain('claveCanal123')->hash());
        $state = new NetworkState();
        $state->introduceUser('001BBBBBB', 'bob', null, '');

        $this->handler->handle($this->privmsg('identify #test otraClave', '001BBBBBB'), $state);
        $this->handler->handle($this->privmsg('topic #test hola', '001BBBBBB'), $state);

        self::assertSame(['001BBBBBB: Contraseña incorrecta.'], [$this->irc->replies[0]]);
        self::assertSame([], $this->projection->topics);
    }

    #[Test]
    public function theFounderCanSetTheTopic(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->handle($this->privmsg('topic #test welcome home'), $this->state);

        self::assertSame(['#test'], $this->projection->topics);
        self::assertSame('welcome home', $this->channels->find(new \Dbots\Domain\Channel\ChannelName('#test'))?->topic);
    }

    #[Test]
    public function theFounderCanDescribeTheChannel(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->handle($this->privmsg('set #test desc Canal de pruebas'), $this->state);

        self::assertSame(
            ['001AAAAAA: La Descripción de #test ha sido cambiada a Canal de pruebas.'],
            $this->irc->replies,
        );
        self::assertSame(
            'Canal de pruebas',
            $this->channels->find(new ChannelName('#test'))?->description,
        );
    }

    #[Test]
    public function infoShowsTheSettingsThatAreSet(): void
    {
        $this->channels->seed('#Test', 'alice');
        $this->handler->handle($this->privmsg('set #test url https://example.org'), $this->state);
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('info #test'), $this->state);

        self::assertSame(['001AAAAAA: URL: https://example.org'], array_slice($this->irc->replies, 1));
    }

    #[Test]
    public function aValueLessSettingIsDisabled(): void
    {
        $this->channels->seed('#Test', 'alice');
        $this->handler->handle($this->privmsg('set #test email alice@example.org'), $this->state);
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('set #test email'), $this->state);

        self::assertSame(['001AAAAAA: Dirección de E-Mail para #test deshabilitada.'], $this->irc->replies);
        self::assertNull($this->channels->find(new ChannelName('#test'))?->email);
    }

    #[Test]
    public function aStrangerCannotChangeTheSettings(): void
    {
        $this->channels->seed('#Test', 'alice');
        $state = new NetworkState();
        $state->introduceUser('001BBBBBB', 'bob', null, 'r');

        $this->handler->handle($this->privmsg('set #test desc robado', '001BBBBBB'), $state);

        self::assertSame(['001BBBBBB: Solo el fundador puede hacer eso.'], $this->irc->replies);
        self::assertNull($this->channels->find(new ChannelName('#test'))?->description);
    }

    #[Test]
    public function settingThePasswordReplacesTheOneIdentifyAccepts(): void
    {
        $this->channels->seed('#Test', 'alice');
        $this->handler->handle($this->privmsg('set #test password claveNueva123'), $this->state);
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('identify #test claveNueva123'), $this->state);

        self::assertSame(['001AAAAAA: Estás identificado como fundador de #test.'], $this->irc->replies);
    }

    #[Test]
    public function itGreetsWithTheEntryMessageReplacingTheNick(): void
    {
        $this->channels->seed('#Test', 'alice');
        $this->handler->handle($this->privmsg('set #test entrymsg Hola <nick>, lee las normas'), $this->state);
        $this->irc->replies = [];

        $this->handler->onMemberJoined('001AAAAAA', '#test', $this->state);

        self::assertSame(['001AAAAAA: Hola alice, lee las normas'], $this->irc->replies);
    }

    #[Test]
    public function topiclockPutsTheStoredTopicBack(): void
    {
        $this->channels->seed('#Test', 'alice');
        $this->handler->handle($this->privmsg('topic #test Bienvenidos'), $this->state);
        $this->handler->handle($this->privmsg('set #test topiclock on'), $this->state);
        $this->actions->topics = [];

        $this->handler->onTopicChanged('001BBBBBB', '#test', 'topic intruso', $this->state);

        self::assertSame(['#test: Bienvenidos'], $this->actions->topics);
    }

    #[Test]
    public function keeptopicRemembersWhatAUserSet(): void
    {
        $this->channels->seed('#Test', 'alice');
        $this->joinAs('001BBBBBB', 'bob');
        $this->handler->handle($this->privmsg('set #test keeptopic on'), $this->state);

        $this->handler->onTopicChanged('001BBBBBB', '#test', 'nuevo tema', $this->state);

        self::assertSame('nuevo tema', $this->channels->find(new ChannelName('#test'))?->topic);
        self::assertSame(['#test'], $this->projection->topics);
    }

    #[Test]
    public function theTopicReactorIgnoresServerSourcesAndItsOwnChanges(): void
    {
        $this->channels->seed('#Test', 'alice');
        $this->handler->handle($this->privmsg('set #test keeptopic on'), $this->state);
        $this->projection->topics = [];

        $this->handler->onTopicChanged('irc.example.org', '#test', 'restaurado por el server', $this->state);
        $this->handler->onTopicChanged('003A00002', '#test', 'lo puse yo', $this->state);

        self::assertSame([], $this->projection->topics);
        self::assertNull($this->channels->find(new ChannelName('#test'))?->topic);
    }

    #[Test]
    public function secureOpsTakesBackOpFromSomebodyBelowTheTable(): void
    {
        $this->channels->seed('#Test', 'alice');
        $this->accounts->save(new Account(new Nick('bob'), 'hash', null, new \DateTimeImmutable()));
        $this->handler->handle($this->privmsg('set #test secureops on'), $this->state);
        $this->joinAs('001BBBBBB', 'bob');

        $this->handler->onChannelModeChanged('001AAAAAAAA', '#test', '+o', ['001BBBBBB'], $this->state);

        self::assertSame(['#test -o 001BBBBBB'], $this->actions->modes);
    }

    #[Test]
    public function secureOpsLeavesAnOpTheTableAllows(): void
    {
        $this->channels->seed('#Test', 'alice');
        $this->accounts->save(new Account(new Nick('carol'), 'hash', null, new \DateTimeImmutable()));
        $this->handler->handle($this->privmsg('access add #test carol 400'), $this->state);
        $this->handler->handle($this->privmsg('set #test secureops on'), $this->state);
        $this->joinAs('001CCCCCC', 'carol', 'carol');

        $this->handler->onChannelModeChanged('001AAAAAAAA', '#test', '+o', ['001CCCCCC'], $this->state);

        self::assertSame([], $this->actions->modes);
    }

    #[Test]
    public function founderStatusIsTheOnlyWayToKeepOwnerStatus(): void
    {
        $this->channels->seed('#Test', 'alice');
        $this->accounts->save(new Account(new Nick('carol'), 'hash', null, new \DateTimeImmutable()));
        $this->handler->handle($this->privmsg('access add #test carol 499'), $this->state);
        $this->joinAs('001CCCCCC', 'carol', 'carol');

        $this->handler->onChannelModeChanged('001AAAAAAAA', '#test', '+q', ['001CCCCCC'], $this->state);

        self::assertSame(['#test -q 001CCCCCC'], $this->actions->modes);
    }

    #[Test]
    public function mlockIsReimposedWhenSomebodyRemovesALockedMode(): void
    {
        $this->channels->seed('#Test', 'alice');
        $this->handler->handle($this->privmsg('set #test mlock nt'), $this->state);
        $this->actions->channelModeLines = [];

        $this->handler->onChannelModeChanged('001AAAAAAAA', '#test', '-n', [], $this->state);

        self::assertSame(['#test +nt'], $this->actions->channelModeLines);
    }

    #[Test]
    public function theReactorIgnoresItsOwnChanges(): void
    {
        $this->channels->seed('#Test', 'alice');
        $this->handler->handle($this->privmsg('set #test mlock nt'), $this->state);
        $this->actions->channelModeLines = [];

        $this->handler->onChannelModeChanged('003A00002', '#test', '-n', [], $this->state);

        self::assertSame([], $this->actions->channelModeLines);
    }

    #[Test]
    public function aRankGrantedByTheTableIsAppliedOnJoin(): void
    {
        $this->channels->seed('#Test', 'alice');
        $this->accounts->save(new Account(new Nick('carol'), 'hash', null, new \DateTimeImmutable()));
        $this->handler->handle($this->privmsg('access add #test carol 300'), $this->state);
        $state = new NetworkState();
        $state->introduceUser('001CCCCCC', 'carol', 'carol', 'r');
        $state->joinChannel('#test', ['001CCCCCC']);

        $this->handler->onMemberJoined('001CCCCCC', '#test', $state);

        self::assertSame(['#test +o 001CCCCCC'], $this->actions->modes);
        self::assertSame([], $this->actions->kicks);
    }

    #[Test]
    public function anAkickedUserIsBannedAndKickedOnJoin(): void
    {
        $this->channels->seed('#Test', 'alice');
        $this->accounts->save(new Account(new Nick('bob'), 'hash', null, new \DateTimeImmutable()));
        $this->handler->handle($this->privmsg('akick #test add bob molesta'), $this->state);
        $state = new NetworkState();
        $state->introduceUser('001BBBBBB', 'bob', 'bob', 'r');
        $state->joinChannel('#test', ['001BBBBBB']);

        $this->handler->onMemberJoined('001BBBBBB', '#test', $state);

        self::assertSame(['#test +b bob'], $this->actions->modes);
        self::assertSame(['#test 001BBBBBB: (alice) -> molesta'], $this->actions->kicks);
    }

    #[Test]
    public function theFounderIsNeverKickedByTheLevels(): void
    {
        $this->channels->seed('#Test', 'alice');
        $this->handler->handle($this->privmsg('levels #test set nojoin 500'), $this->state);

        $this->handler->onMemberJoined('001AAAAAA', '#test', $this->state);

        self::assertSame([], $this->actions->kicks);
    }

    #[Test]
    public function aChannelWithoutEntryMessageIsSilent(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->onMemberJoined('001AAAAAA', '#test', $this->state);
        $this->handler->onMemberJoined('001AAAAAA', '#sinRegistrar', $this->state);

        self::assertSame([], $this->irc->replies);
    }

    #[Test]
    public function theFounderCanOpSomebodyInTheChannel(): void
    {
        $this->channels->seed('#Test', 'alice');
        $this->joinAs('001BBBBBB', 'bob');

        $this->handler->handle($this->privmsg('op #test bob'), $this->state);

        self::assertSame(['#test +o 001BBBBBB'], $this->actions->modes);
        self::assertSame(['#test: alice hace OP a bob.'], $this->actions->notices);
    }

    #[Test]
    public function anAccessUserCanOpWhenTheTableAllowsIt(): void
    {
        $this->channels->seed('#Test', 'alice');
        $this->accounts->save(new Account(new Nick('carol'), 'hash', null, new \DateTimeImmutable()));
        $this->handler->handle($this->privmsg('access add #test carol 100'), $this->state);
        $this->joinAs('001CCCCCC', 'carol', 'carol');
        $this->joinAs('001BBBBBB', 'bob');

        $this->handler->handle($this->privmsg('op #test bob', '001CCCCCC'), $this->state);

        self::assertSame(['#test +o 001BBBBBB'], $this->actions->modes);
    }

    #[Test]
    public function itWillNotDeopSomebodyWhoStandsAboveTheSender(): void
    {
        $this->channels->seed('#Test', 'alice');
        $this->accounts->save(new Account(new Nick('carol'), 'hash', null, new \DateTimeImmutable()));
        $this->handler->handle($this->privmsg('access add #test carol 100'), $this->state);
        $this->joinAs('001CCCCCC', 'carol', 'carol');
        $this->joinAs('001AAAAAA', 'alice');
        $this->state->applyModeChange('001AAAAAA', '+o');

        $this->irc->replies = [];
        $this->handler->handle($this->privmsg('deop #test alice', '001CCCCCC'), $this->state);

        self::assertSame([], $this->actions->modes);
        self::assertSame(['001CCCCCC: Permiso denegado.'], $this->irc->replies);
    }

    #[Test]
    public function aRankOnANickWeNeverSawIsRefused(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->handle($this->privmsg('op #test fantasma'), $this->state);

        self::assertSame([], $this->actions->modes);
        self::assertSame(['001AAAAAA: El usuario fantasma no está en el canal.'], $this->irc->replies);
    }

    #[Test]
    public function inviteAndUnbanActOnTheChannel(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->handle($this->privmsg('invite #test'), $this->state);
        $this->handler->handle($this->privmsg('unban #test'), $this->state);

        self::assertSame(['#test 001AAAAAA'], $this->actions->invites);
        self::assertContains('#test -b alice', $this->actions->modes);
        self::assertContains('001AAAAAA: Has sido desbaneado de #test.', $this->irc->replies);
    }

    #[Test]
    public function setStoresTheSwitchesWithTheirOwnWording(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->handle($this->privmsg('set #test topiclock on'), $this->state);
        $this->handler->handle($this->privmsg('set #test secureops on'), $this->state);
        $this->handler->handle($this->privmsg('set #test autolimit on'), $this->state);
        $this->handler->handle($this->privmsg('set #test noexpire off'), $this->state);
        $this->handler->handle($this->privmsg('set #test pbans on'), $this->state);

        self::assertSame([
            '001AAAAAA: Opción de candado de tópico activada.',
            '001AAAAAA: Opción de Secure Ops activada.',
            '001AAAAAA: El canal #test será auto-limitado.',
            '001AAAAAA: El canal #test expirará si no es usado.',
            '001AAAAAA: Protección de bans activada para el canal #test',
        ], $this->irc->replies);
    }

    #[Test]
    public function setRejectsAValueThatIsNotOnOrOff(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->handle($this->privmsg('set #test topiclock si'), $this->state);
        $this->handler->handle($this->privmsg('set #test inventada on'), $this->state);

        self::assertSame([
            '001AAAAAA: Error de Sintaxis: SET <canal> TOPICLOCK {ON|OFF}',
            '001AAAAAA: Opción desconocida INVENTADA. Usa AYUDA SET.',
        ], $this->irc->replies);
    }

    #[Test]
    public function setMlockAppliesAndRemembersTheModes(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->handle($this->privmsg('set #test mlock ntx'), $this->state);
        $this->handler->handle($this->privmsg('set #test mlock'), $this->state);

        self::assertSame([
            '001AAAAAA: Modo x es desconocido e ignorado.',
            '001AAAAAA: Candado de modo de canal #test cambiado a +nt.',
            '001AAAAAA: Candado de modo de canal #test borrado.',
        ], $this->irc->replies);
        self::assertSame(['#test +nt'], $this->actions->channelModeLines);
    }

    #[Test]
    public function listFiltersByPatternAndCountsWhatItShows(): void
    {
        $this->channels->seed('#Test', 'alice');
        $this->channels->seed('#Otro', 'bob');
        $state = new NetworkState();
        $state->introduceUser('001OOOOOO', 'Operador', null, 'o');

        $this->handler->handle($this->privmsg('list #te*', '001OOOOOO'), $state);

        self::assertSame('001OOOOOO: Lista de Canales que satisfacen #te*:', $this->irc->replies[0]);
        self::assertContains('001OOOOOO: #test - ACTIVO (BDD)', $this->irc->replies);
        self::assertNotContains('001OOOOOO: #otro - ACTIVO (BDD)', $this->irc->replies);
        self::assertSame('001OOOOOO: Fin de la lista, 1/2 entradas mostradas.', $this->irc->replies[array_key_last($this->irc->replies)]);
    }

    #[Test]
    public function getpassSaysThePasswordIsHashed(): void
    {
        $this->channels->seed('#Test', 'alice', Password::fromPlain('claveCanal123')->hash());
        $state = new NetworkState();
        $state->introduceUser('001OOOOOO', 'Operador', null, 'o');

        $this->handler->handle($this->privmsg('getpass #test', '001OOOOOO'), $state);

        self::assertSame(
            ['001OOOOOO: La contraseña de #test está cifrada y no se puede recuperar. Usa SET PASSWORD para cambiarla.'],
            $this->irc->replies,
        );
    }

    #[Test]
    public function theOperOnlyReadsRefuseAFounder(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->handle($this->privmsg('list #te*'), $this->state);
        $this->handler->handle($this->privmsg('getpass #test'), $this->state);

        self::assertSame(
            ['001AAAAAA: Permiso denegado.', '001AAAAAA: Permiso denegado.'],
            $this->irc->replies,
        );
    }

    #[Test]
    public function forbidDropsTheProfileAndPublishesTheRecord(): void
    {
        $this->channels->seed('#Test', 'alice');
        $state = new NetworkState();
        $state->introduceUser('001OOOOOO', 'Operador', null, 'o');

        $this->handler->handle($this->privmsg('forbid #test spam', '001OOOOOO'), $state);
        $this->handler->handle($this->privmsg('listforbid', '001OOOOOO'), $state);

        self::assertSame('001OOOOOO: El canal #test ha sido prohibido.', $this->irc->replies[0]);
        self::assertNull($this->channels->find(new ChannelName('#test')));
        self::assertContains('#test', $this->projection->dropped);
        self::assertSame(['#test on'], $this->projection->forbids);
        self::assertContains('001OOOOOO: #test (Operador) spam', $this->irc->replies);
    }

    #[Test]
    public function forbidIsOperOnlyAndRefusesADuplicate(): void
    {
        $this->channels->seed('#Test', 'alice');
        $state = new NetworkState();
        $state->introduceUser('001OOOOOO', 'Operador', null, 'o');

        $this->handler->handle($this->privmsg('forbid #test spam'), $this->state);
        $this->handler->handle($this->privmsg('forbid #test spam', '001OOOOOO'), $state);
        $this->handler->handle($this->privmsg('forbid #test otra vez', '001OOOOOO'), $state);

        self::assertSame([
            '001AAAAAA: Permiso denegado.',
            '001OOOOOO: El canal #test ha sido prohibido.',
            '001OOOOOO: El canal #test ya está en la lista de forbid.',
        ], $this->irc->replies);
    }

    #[Test]
    public function unforbidReleasesTheChannel(): void
    {
        $this->channels->seed('#Test', 'alice');
        $state = new NetworkState();
        $state->introduceUser('001OOOOOO', 'Operador', null, 'o');

        $this->handler->handle($this->privmsg('forbid #test spam', '001OOOOOO'), $state);
        $this->handler->handle($this->privmsg('unforbid #test', '001OOOOOO'), $state);
        $this->handler->handle($this->privmsg('unforbid #otro', '001OOOOOO'), $state);

        self::assertContains('001OOOOOO: El canal #test ha sido liberado.', $this->irc->replies);
        self::assertSame('001OOOOOO: El canal #otro no está en la lista de forbid.', $this->irc->replies[array_key_last($this->irc->replies)]);
        self::assertSame(['#test on', '#test off'], $this->projection->forbids);
    }

    #[Test]
    public function aRepresentativeCountsAsAnOperWithoutIrcModes(): void
    {
        $this->channels->seed('#Test', 'alice');
        $ranks = new \Dbots\Tests\Support\InMemoryStaffRankRepository();
        $ranks->assign('alice', \Dbots\Domain\Staff\StaffRank::Operador);

        // Rebuild the handler with a ladder that knows alice, then suspend: an
        // oper-only command that does not depend on IRC user modes.
        $this->handler = new ChanServCommandHandler(
            new DropChannelHandler($this->channels, new InMemoryChannelAccessRepository()),
            $this->channels,
            new InMemoryChannelAccessRepository(),
            new InMemoryChannelLevelRepository(),
            $this->actions,
            new InMemoryChannelAkickRepository(),
            new InMemoryChannelForbidRepository(),
            new InMemoryChannelOptionRepository(),
            $this->accounts,
            new \Dbots\Staff\Application\StaffAuthority($ranks),
            $this->projection,
            new AccountResolver($this->accounts),
            $this->irc,
            'CHaN',
            '003A00002',
            ChanServHelp::create(),
        );

        $this->handler->handle($this->privmsg('suspend #test incumple normas'), $this->state);

        self::assertSame(['001AAAAAA: El canal #test ha sido suspendido.'], $this->irc->replies);
    }

    #[Test]
    public function anOperSuspendsAndTheChannelStopsAcceptingChanges(): void
    {
        $this->channels->seed('#Test', 'alice');
        $state = new NetworkState();
        $state->introduceUser('001OOOOOO', 'Operador', null, 'o');

        $this->handler->handle($this->privmsg('suspend #test incumple normas', '001OOOOOO'), $state);
        $this->handler->handle($this->privmsg('set #test desc otra cosa'), $this->state);

        self::assertSame([
            '001OOOOOO: El canal #test ha sido suspendido.',
            '001AAAAAA: El canal #test está suspendido.',
        ], $this->irc->replies);
        self::assertSame(['#test'], $this->projection->suspensions);
    }

    #[Test]
    public function suspendAndUnsuspendNeedAnOper(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->handle($this->privmsg('suspend #test motivo'), $this->state);
        $this->handler->handle($this->privmsg('listsuspend'), $this->state);

        self::assertSame(['001AAAAAA: Permiso denegado.', '001AAAAAA: Permiso denegado.'], $this->irc->replies);
    }

    #[Test]
    public function unsuspendReleasesTheChannelAndListsuspendNamesIt(): void
    {
        $this->channels->seed('#Test', 'alice');
        $state = new NetworkState();
        $state->introduceUser('001OOOOOO', 'Operador', null, 'o');

        $this->handler->handle($this->privmsg('suspend #test incumple normas', '001OOOOOO'), $state);
        $this->handler->handle($this->privmsg('listsuspend', '001OOOOOO'), $state);
        $this->handler->handle($this->privmsg('unsuspend #test', '001OOOOOO'), $state);

        self::assertContains('001OOOOOO: #test - Motivo: incumple normas', $this->irc->replies);
        self::assertSame('001OOOOOO: El canal #test ha sido liberado.', $this->irc->replies[array_key_last($this->irc->replies)]);
        self::assertSame(['#test', '#test'], $this->projection->suspensions);
    }

    #[Test]
    public function akickAddsListsAndRemovesAEntry(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->handle($this->privmsg('akick #test add bob molesta'), $this->state);
        $this->handler->handle($this->privmsg('akick #test list'), $this->state);
        $this->handler->handle($this->privmsg('akick #test del bob'), $this->state);

        self::assertSame('001AAAAAA: bob agregado a la lista de autokick de #test.', $this->irc->replies[0]);
        self::assertContains('001AAAAAA: Nick: bob - Motivo: (alice) -> molesta', $this->irc->replies);
        self::assertSame('001AAAAAA: Fin de la lista.', $this->irc->replies[array_key_last($this->irc->replies) - 1]);
        self::assertSame('001AAAAAA: bob borrado de la lista de autokick de #test.', $this->irc->replies[array_key_last($this->irc->replies)]);
    }

    #[Test]
    public function akickRefusesAnUnregisteredNickAndADuplicate(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->handle($this->privmsg('akick #test add desconocido'), $this->state);
        $this->handler->handle($this->privmsg('akick #test add bob'), $this->state);
        $this->handler->handle($this->privmsg('akick #test add bob otra vez'), $this->state);

        self::assertSame([
            '001AAAAAA: El nick desconocido no está registrado.',
            '001AAAAAA: bob agregado a la lista de autokick de #test.',
            '001AAAAAA: bob ya existe en la lista de autokicks de #test.',
        ], $this->irc->replies);
    }

    #[Test]
    public function akickEnforceRemovesTheMembersWeCanSee(): void
    {
        $this->channels->seed('#Test', 'alice');
        $this->joinAs('001BBBBBB', 'bob');
        $this->handler->handle($this->privmsg('akick #test add bob molesta'), $this->state);
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('akick #test enforce'), $this->state);

        self::assertSame(['#test 001BBBBBB: (alice) -> molesta'], $this->actions->kicks);
        self::assertSame(['001AAAAAA: Han sido expulsados del canal los nicks con akick.'], $this->irc->replies);
    }

    #[Test]
    public function akickListingHasALowerThresholdThanChangingIt(): void
    {
        $this->channels->seed('#Test', 'alice');
        $this->accounts->save(new Account(new Nick('carol'), 'hash', null, new \DateTimeImmutable()));
        $this->handler->handle($this->privmsg('access add #test carol 350'), $this->state);
        $this->irc->replies = [];
        $state = new NetworkState();
        $state->introduceUser('001CCCCCC', 'carol', 'carol', 'r');

        // AKICK-LIST is 300, AKICK-CHANGE is 499.
        $this->handler->handle($this->privmsg('akick #test list', '001CCCCCC'), $state);
        $this->handler->handle($this->privmsg('akick #test add bob', '001CCCCCC'), $state);

        self::assertSame('001CCCCCC: Lista de autokick de #test:', $this->irc->replies[0]);
        self::assertSame('001CCCCCC: Permiso denegado.', $this->irc->replies[array_key_last($this->irc->replies)]);
    }

    #[Test]
    public function clearModesWipesTheModeSetInOneLine(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->handle($this->privmsg('clear #test modes'), $this->state);

        self::assertSame(['#test -ntimlpsRMG'], $this->actions->channelModeLines);
        self::assertSame(['001AAAAAA: Todos los modos en canal #test han sido borrados.'], $this->irc->replies);
    }

    #[Test]
    public function clearOpsDropsEveryOpWeKnowAbout(): void
    {
        $this->channels->seed('#Test', 'alice');
        $this->joinAs('001BBBBBB', 'bob');
        $this->joinAs('001CCCCCC', 'carol');
        $this->state->applyChannelModeChange('#test', '+o', ['001BBBBBB']);
        $this->state->applyChannelModeChange('#test', '+o', ['001CCCCCC']);

        $this->handler->handle($this->privmsg('clear #test ops'), $this->state);

        self::assertSame(['#test -o 001BBBBBB', '#test -o 001CCCCCC'], $this->actions->modes);
        self::assertSame(
            ['001AAAAAA: Modo +o de operadores han sido desactivados del canal #test.'],
            $this->irc->replies,
        );
    }

    #[Test]
    public function clearTopicRestoresTheStoredOne(): void
    {
        $this->channels->seed('#Test', 'alice');
        $this->handler->handle($this->privmsg('topic #test Bienvenidos'), $this->state);
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('clear #test topic'), $this->state);

        self::assertSame(['#test: Bienvenidos'], $this->actions->topics);
        self::assertSame(['001AAAAAA: Tópico de #test restaurado.'], $this->irc->replies);
    }

    #[Test]
    public function clearBansLiftsEveryBanWeSaw(): void
    {
        $this->channels->seed('#Test', 'alice');
        $this->state->applyChannelModeChange('#test', '+b', ['*!*@spam.example']);

        $this->handler->handle($this->privmsg('clear #test bans'), $this->state);

        self::assertContains('#test -b *!*@spam.example', $this->actions->modes);
        self::assertSame(['001AAAAAA: Todos los bans en canal #test han sido borrados.'], $this->irc->replies);
    }

    #[Test]
    public function clearUsersKicksEveryMemberAsTheServer(): void
    {
        $this->channels->seed('#Test', 'alice');
        $this->joinAs('001BBBBBB', 'bob');
        $this->joinAs('001CCCCCC', 'carol');

        $this->handler->handle($this->privmsg('clear #test users'), $this->state);

        self::assertSame(
            ['#test 001BBBBBB: CLEAR USERS', '#test 001CCCCCC: CLEAR USERS'],
            $this->actions->kicks,
        );
        self::assertSame(['001AAAAAA: Todos los usuarios han sido expulsados del canal #test.'], $this->irc->replies);
    }

    #[Test]
    public function clearNeedsFounderLevelAndAValidOption(): void
    {
        $this->channels->seed('#Test', 'alice');
        $state = new NetworkState();
        $state->introduceUser('001BBBBBB', 'bob', null, 'r');

        $this->handler->handle($this->privmsg('clear #test modes', '001BBBBBB'), $state);
        $this->handler->handle($this->privmsg('clear #test nada'), $this->state);
        $this->handler->handle($this->privmsg('clear #test'), $this->state);

        self::assertSame([
            '001BBBBBB: Permiso denegado.',
            '001AAAAAA: Error de Sintaxis: CLEAR <canal> <opción>',
            '001AAAAAA: Error de Sintaxis: CLEAR <canal> <opción>',
        ], $this->irc->replies);
    }

    #[Test]
    public function accessLevelsFollowTheTable(): void
    {
        $this->channels->seed('#Test', 'alice');
        $this->accounts->save(new Account(new Nick('carol'), 'hash', null, new \DateTimeImmutable()));
        $this->handler->handle($this->privmsg('access add #test carol 450'), $this->state);
        $state = new NetworkState();
        $state->introduceUser('001CCCCCC', 'carol', 'carol', 'r');
        $this->irc->replies = [];

        // ACC-CHANGE is 499, so 450 is not enough to change the list...
        $this->handler->handle($this->privmsg('access add #test bob 400', '001CCCCCC'), $state);

        self::assertSame(['001CCCCCC: Permiso denegado.'], $this->irc->replies);
    }

    #[Test]
    public function accessLevelsComeFromTheChannelTable(): void
    {
        $this->channels->seed('#Test', 'alice');
        $this->accounts->save(new Account(new Nick('carol'), 'hash', null, new \DateTimeImmutable()));
        $this->handler->handle($this->privmsg('access add #test carol 400'), $this->state);
        $this->handler->handle($this->privmsg('levels #test set acc-list 400'), $this->state);
        $this->irc->replies = [];
        $state = new NetworkState();
        $state->introduceUser('001CCCCCC', 'carol', 'carol', 'r');

        // ACC-LIST now accepts 400, so carol can list without being founder.
        $this->handler->handle($this->privmsg('access list #test', '001CCCCCC'), $state);

        self::assertSame('001CCCCCC: Lista de acceso de #test:', $this->irc->replies[0]);
        self::assertContains('001CCCCCC: Nick: carol - Nivel: 400', $this->irc->replies);
    }

    #[Test]
    public function accessRejectsLevelsOutsideTheRange(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->handle($this->privmsg('access add #test bob 500'), $this->state);
        $this->handler->handle($this->privmsg('access add #test bob mucho'), $this->state);
        $this->handler->handle($this->privmsg('access add #test bob'), $this->state);

        self::assertSame([
            '001AAAAAA: El nivel de registro debe estar comprendido entre -1 y 499.',
            '001AAAAAA: El nivel del registro debe de ser un número.',
            '001AAAAAA: Error de Sintaxis: ACCESS <canal> ADD <nick> <nivel>',
        ], $this->irc->replies);
    }

    #[Test]
    public function levelsListStartsFromTheReferenceTable(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->handle($this->privmsg('levels #test list'), $this->state);

        self::assertSame('001AAAAAA: Configuración de niveles de acceso para el canal #test:', $this->irc->replies[0]);
        self::assertContains('001AAAAAA:     OPDEOP             100', $this->irc->replies);
        self::assertContains('001AAAAAA:     SET                500', $this->irc->replies);
        self::assertContains('001AAAAAA:     AUTODEVOICE        -1', $this->irc->replies);
        self::assertSame('001AAAAAA: Fin de la configuración.', $this->irc->replies[array_key_last($this->irc->replies)]);
    }

    #[Test]
    public function levelsSetChangesOneThreshold(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->handle($this->privmsg('levels #test set autoop 250'), $this->state);

        self::assertSame(
            ['001AAAAAA: El nivel para AUTOOP en canal #test cambiado a 250.'],
            $this->irc->replies,
        );
    }

    #[Test]
    public function levelsResetRestoresTheDefaults(): void
    {
        $this->channels->seed('#Test', 'alice');
        $this->handler->handle($this->privmsg('levels #test set autoop 250'), $this->state);
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('levels #test reset'), $this->state);
        $this->handler->handle($this->privmsg('levels #test list'), $this->state);

        self::assertSame('001AAAAAA: Niveles de acceso de #test configurados a niveles originales.', $this->irc->replies[0]);
        self::assertContains('001AAAAAA:     AUTOOP             300', $this->irc->replies);
    }

    #[Test]
    public function levelsRejectsUnknownKeysAndOutOfRangeValues(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->handle($this->privmsg('levels #test set kick 400'), $this->state);
        $this->handler->handle($this->privmsg('levels #test set autoop 501'), $this->state);
        $this->handler->handle($this->privmsg('levels #test set autoop mucho'), $this->state);

        self::assertSame([
            '001AAAAAA: Opción KICK desconocida.',
            '001AAAAAA: El Nivel debe de estar entre -1 y 500.',
            '001AAAAAA: Error de Sintaxis: LEVELS <canal> SET <clave> <nivel>',
        ], $this->irc->replies);
    }

    #[Test]
    public function aStrangerCannotReadOrChangeTheLevels(): void
    {
        $this->channels->seed('#Test', 'alice');
        $state = new NetworkState();
        $state->introduceUser('001BBBBBB', 'bob', null, 'r');

        $this->handler->handle($this->privmsg('levels #test list', '001BBBBBB'), $state);
        $this->handler->handle($this->privmsg('levels #test set autoop 1', '001BBBBBB'), $state);

        self::assertSame(['001BBBBBB: Solo el fundador puede hacer eso.', '001BBBBBB: Solo el fundador puede hacer eso.'], $this->irc->replies);
    }

    #[Test]
    public function itIgnoresMessagesForAnotherService(): void
    {
        $message = new IrcMessage('001AAAAAA', 'PRIVMSG', ['NiCK'], 'register #test');

        $this->handler->handle($message, $this->state);

        self::assertSame([], $this->irc->replies);
    }

    #[Test]
    public function theFounderCanTransferOwnership(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->handle($this->privmsg('set #test founder bob'), $this->state);

        self::assertSame('bob', $this->channels->find(new ChannelName('#test'))?->founder);
        self::assertSame(['#test'], $this->projection->founders);
        self::assertSame(['001AAAAAA: El Fundador de #test cambiado a bob.'], $this->irc->replies);
    }

    #[Test]
    public function founderTransferRejectsTheCurrentFounder(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->handle($this->privmsg('set #test founder alice'), $this->state);

        self::assertSame('alice', $this->channels->find(new ChannelName('#test'))?->founder);
        self::assertSame(['001AAAAAA: alice ya es el fundador de #test.'], $this->irc->replies);
    }

    #[Test]
    public function founderTransferRejectsTheCurrentSuccessor(): void
    {
        $this->channels->seed('#Test', 'alice');
        $this->channels->updateSuccessor(new \Dbots\Domain\Channel\Channel(
            new ChannelName('#test'),
            'alice',
            new \DateTimeImmutable(),
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            'bob',
        ));

        $this->handler->handle($this->privmsg('set #test founder bob'), $this->state);

        self::assertSame('alice', $this->channels->find(new ChannelName('#test'))?->founder);
        self::assertSame(['001AAAAAA: El fundador no puede ser el mismo que el sucesor.'], $this->irc->replies);
    }

    #[Test]
    public function founderTransferRejectsAnUnregisteredTarget(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->handle($this->privmsg('set #test founder desconocido'), $this->state);

        self::assertSame('alice', $this->channels->find(new ChannelName('#test'))?->founder);
        self::assertSame(['001AAAAAA: El nick desconocido no está registrado.'], $this->irc->replies);
    }

    #[Test]
    public function theFounderCanSetAndClearASuccessor(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->handle($this->privmsg('set #test successor bob'), $this->state);

        self::assertSame('bob', $this->channels->find(new ChannelName('#test'))?->successor);
        self::assertSame(['001AAAAAA: El Sucesor de #test cambiado a bob.'], $this->irc->replies);

        $this->irc->replies = [];
        $this->handler->handle($this->privmsg('set #test successor'), $this->state);

        self::assertNull($this->channels->find(new ChannelName('#test'))?->successor);
        self::assertSame(['001AAAAAA: El Sucesor para el canal #test ha sido deshabilitado.'], $this->irc->replies);
    }

    #[Test]
    public function clearingANonExistentSuccessorReportsIt(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->handle($this->privmsg('set #test successor'), $this->state);

        self::assertSame(['001AAAAAA: El canal #test no tiene sucesor.'], $this->irc->replies);
    }

    #[Test]
    public function successorRejectsTheCurrentFounder(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->handle($this->privmsg('set #test successor alice'), $this->state);

        self::assertNull($this->channels->find(new ChannelName('#test'))?->successor);
        self::assertSame(['001AAAAAA: El fundador no puede ser el mismo que el sucesor.'], $this->irc->replies);
    }

    #[Test]
    public function aStrangerCannotChangeTheFounder(): void
    {
        $this->channels->seed('#Test', 'alice');
        $state = new NetworkState();
        $state->introduceUser('001BBBBBB', 'bob', null, 'r');

        $this->handler->handle($this->privmsg('set #test founder bob', '001BBBBBB'), $state);

        self::assertSame('alice', $this->channels->find(new ChannelName('#test'))?->founder);
        self::assertSame(['001BBBBBB: Solo el fundador puede hacer eso.'], $this->irc->replies);
    }

    private function joinAs(string $uid, string $nick, ?string $account = null): void
    {
        if (!$this->state->hasNick($nick)) {
            $this->state->introduceUser($uid, $nick, $account, null === $account ? '' : 'r');
        }

        $this->state->joinChannel('#test', [$uid]);
    }

    private function privmsg(string $text, string $uid = '001AAAAAA'): IrcMessage
    {
        return new IrcMessage($uid, 'PRIVMSG', ['CHaN'], $text);
    }
}
