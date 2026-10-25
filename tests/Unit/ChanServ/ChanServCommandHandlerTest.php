<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\ChanServ;

use Dbots\ChanServ\Adapter\In\Irc\ChanServCommandHandler;
use Dbots\ChanServ\Application\DropChannel\DropChannelHandler;
use Dbots\ChanServ\Application\Port\Out\ChannelProjectionInterface;
use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\NetworkState;
use Dbots\Domain\Account\Account;
use Dbots\Domain\Account\Nick;
use Dbots\Domain\Account\Password;
use Dbots\Domain\Channel\ChannelName;
use Dbots\Irc\Application\AccountResolver;
use Dbots\Tests\Support\InMemoryAccountRepository;
use Dbots\Tests\Support\InMemoryChannelAccessRepository;
use Dbots\Tests\Support\RecordingChannelProjection;
use Dbots\Tests\Support\InMemoryChannelLevelRepository;
use Dbots\Tests\Support\InMemoryChannelRepository;
use Dbots\Tests\Support\RecordingIrc;
use Dbots\ChanServ\Application\Help\ChanServHelp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChanServCommandHandlerTest extends TestCase
{
    private InMemoryChannelRepository $channels;
    private InMemoryAccountRepository $accounts;
    private RecordingIrc $irc;
    private RecordingChannelProjection $projection;
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
        $this->handler = new ChanServCommandHandler(
            new DropChannelHandler($this->channels, new InMemoryChannelAccessRepository()),
            $this->channels,
            new InMemoryChannelAccessRepository(),
            new InMemoryChannelLevelRepository(),
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
        self::assertSame(['#test:bob'], $this->projection->accessAdded);
        self::assertSame(
            ['001AAAAAA: bob agregado a la lista de acceso de #test con nivel 400.'],
            $this->irc->replies,
        );

        $this->handler->handle($this->privmsg('access list #test'), $this->state);
        self::assertContains('001AAAAAA: Nick: bob - Nivel: 400', $this->irc->replies);
        self::assertSame('001AAAAAA: Fin de la lista.', end($this->irc->replies));

        $this->handler->handle($this->privmsg('access del #test bob'), $this->state);
        self::assertSame(['#test:bob'], $this->projection->accessRemoved);
        self::assertSame('001AAAAAA: bob borrado de la lista de acceso de #test.', end($this->irc->replies));
    }

    #[Test]
    public function aStrangerCannotGrantAccess(): void
    {
        $this->channels->seed('#Test', 'alice');
        $state = new NetworkState();
        $state->introduceUser('001BBBBBB', 'bob', null, 'r');

        $this->handler->handle($this->privmsg('access add #test bob 400', '001BBBBBB'), $state);

        self::assertSame([], $this->projection->accessAdded);
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

        $this->handler->announceEntry('001AAAAAA', '#test', $this->state);

        self::assertSame(['001AAAAAA: Hola alice, lee las normas'], $this->irc->replies);
    }

    #[Test]
    public function aChannelWithoutEntryMessageIsSilent(): void
    {
        $this->channels->seed('#Test', 'alice');

        $this->handler->announceEntry('001AAAAAA', '#test', $this->state);
        $this->handler->announceEntry('001AAAAAA', '#sinRegistrar', $this->state);

        self::assertSame([], $this->irc->replies);
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

    private function privmsg(string $text, string $uid = '001AAAAAA'): IrcMessage
    {
        return new IrcMessage($uid, 'PRIVMSG', ['CHaN'], $text);
    }
}

