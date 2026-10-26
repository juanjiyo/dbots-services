<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\NoServ;

use DateTimeImmutable;
use Dbots\Domain\Account\Account;
use Dbots\Domain\Account\Nick;
use Dbots\Domain\Staff\StaffRank;
use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\NetworkState;
use Dbots\NoServ\Adapter\In\Irc\NoServCommandHandler;
use Dbots\NoServ\Application\Help\NoServHelp;
use Dbots\Staff\Application\StaffAuthority;
use Dbots\Tests\Support\InMemoryAccountRepository;
use Dbots\Tests\Support\InMemoryMotdNickRepository;
use Dbots\Tests\Support\InMemoryNewsMessageRepository;
use Dbots\Tests\Support\InMemoryNewsSubscriptionRepository;
use Dbots\Tests\Support\InMemoryServerEntryMessageRepository;
use Dbots\Tests\Support\InMemoryStaffRankRepository;
use Dbots\Tests\Support\RecordingEmailSender;
use Dbots\Tests\Support\RecordingIrc;
use Dbots\Tests\Support\RecordingTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function explode;
use function implode;
use function preg_match;
use function str_contains;

final class NoServCommandHandlerTest extends TestCase
{
    private RecordingTransport $transport;
    private RecordingIrc $irc;
    private RecordingEmailSender $emailSender;
    private InMemoryAccountRepository $accounts;
    private InMemoryStaffRankRepository $ranks;
    private InMemoryNewsSubscriptionRepository $subscriptions;
    private InMemoryNewsMessageRepository $motdMessages;
    private InMemoryNewsMessageRepository $representantesMessages;
    private InMemoryServerEntryMessageRepository $serverMessages;
    private InMemoryMotdNickRepository $motdNicks;
    private StaffAuthority $staff;
    private NoServCommandHandler $handler;
    private NetworkState $state;

    protected function setUp(): void
    {
        $this->transport = new RecordingTransport();
        $this->irc = new RecordingIrc();
        $this->emailSender = new RecordingEmailSender();
        $this->accounts = new InMemoryAccountRepository();
        $this->ranks = new InMemoryStaffRankRepository();
        $this->subscriptions = new InMemoryNewsSubscriptionRepository();
        $this->motdMessages = new InMemoryNewsMessageRepository();
        $this->representantesMessages = new InMemoryNewsMessageRepository();
        $this->serverMessages = new InMemoryServerEntryMessageRepository();
        $this->motdNicks = new InMemoryMotdNickRepository();
        $this->staff = new StaffAuthority($this->ranks);

        $this->handler = new NoServCommandHandler(
            $this->irc,
            $this->transport,
            $this->staff,
            $this->accounts,
            $this->subscriptions,
            $this->motdMessages,
            $this->representantesMessages,
            $this->serverMessages,
            $this->motdNicks,
            $this->emailSender,
            'NoServ',
            '003A00011',
            NoServHelp::create(),
            ['NiCK', 'CHaN', 'MeMo', 'OPeR', 'CReG', 'HeLP', 'CeNTeR', 'CLoNeS', 'GlobalServ'],
        );

        $this->state = new NetworkState();
        $this->addUser('001AAAAAA', 'admin', StaffRank::Administrador);
        $this->state->introduceUser('001BBBBBB', 'user', null, 'i', 'user.example');
    }

    #[Test]
    public function altaSubscribesAnIdentifiedUser(): void
    {
        $this->handler->handle($this->privmsg('ALTA', '001AAAAAA'), $this->state);

        self::assertTrue($this->subscriptions->isSubscribed('admin'));
        self::assertSame(['001AAAAAA: Tu nick acaba de ser dado de alta en el servicio de noticias.'], $this->irc->replies);
    }

    #[Test]
    public function altaRequiresIdentification(): void
    {
        $this->handler->handle($this->privmsg('ALTA', '001BBBBBB'), $this->state);

        self::assertFalse($this->subscriptions->isSubscribed('user'));
        self::assertSame(['001BBBBBB: Debes identificarte para poder usar este servicio.'], $this->irc->replies);
    }

    #[Test]
    public function altaRejectsAlreadySubscribed(): void
    {
        $this->subscriptions->subscribe('admin');

        $this->handler->handle($this->privmsg('ALTA', '001AAAAAA'), $this->state);

        self::assertSame(['001AAAAAA: Tu nick ya está dado de alta en el servicio.'], $this->irc->replies);
    }

    #[Test]
    public function bajaUnsubscribesAnIdentifiedUser(): void
    {
        $this->subscriptions->subscribe('admin');

        $this->handler->handle($this->privmsg('BAJA', '001AAAAAA'), $this->state);

        self::assertFalse($this->subscriptions->isSubscribed('admin'));
        self::assertSame(['001AAAAAA: Tu nick acaba de ser dado de baja en el servicio de noticias.'], $this->irc->replies);
    }

    #[Test]
    public function bajaRequiresIdentification(): void
    {
        $this->handler->handle($this->privmsg('BAJA', '001BBBBBB'), $this->state);

        self::assertSame(['001BBBBBB: Debes identificarte para poder usar este servicio.'], $this->irc->replies);
    }

    #[Test]
    public function bajaRejectsNotSubscribed(): void
    {
        $this->handler->handle($this->privmsg('BAJA', '001AAAAAA'), $this->state);

        self::assertSame(['001AAAAAA: Tu nick no está dado de alta en el servicio.'], $this->irc->replies);
    }

    #[Test]
    public function emailSendsToSubscribedAccountsWithEmail(): void
    {
        $this->subscriptions->subscribe('admin');
        $this->subscriptions->subscribe('user');
        $this->accounts->save(new Account(new Nick('admin'), 'hash', 'admin@example.com', new DateTimeImmutable()));

        $this->handler->handle($this->privmsg('EMAIL Aviso importante', '001AAAAAA'), $this->state);

        self::assertCount(1, $this->emailSender->sent);
        self::assertSame('admin@example.com', $this->emailSender->sent[0]['to']);
        self::assertSame('Aviso importante', $this->emailSender->sent[0]['body']);
        self::assertSame(['001AAAAAA: E-mail enviado a todos los usuarios dados de alta en el servicio de noticias.'], $this->irc->replies);
    }

    #[Test]
    public function emailRequiresAdmin(): void
    {
        $this->handler->handle($this->privmsg('EMAIL Aviso', '001BBBBBB'), $this->state);

        self::assertSame(['001BBBBBB: Permiso denegado.'], $this->irc->replies);
        self::assertSame([], $this->emailSender->sent);
    }

    #[Test]
    public function emailRequiresAMessage(): void
    {
        $this->handler->handle($this->privmsg('EMAIL', '001AAAAAA'), $this->state);

        self::assertSame(['001AAAAAA: Error de Sintaxis: EMAIL <mensaje>'], $this->irc->replies);
    }

    #[Test]
    public function motdAddStoresAndBroadcasts(): void
    {
        $this->handler->handle($this->privmsg('MOTD ADD Nueva funcionalidad', '001AAAAAA'), $this->state);

        self::assertCount(1, $this->motdMessages->all());
        self::assertSame('Nueva funcionalidad', $this->motdMessages->all()[0]['text']);
        self::assertSame([
            ':003A00011 PRIVMSG admin :' . "\x033[ Noticias de la Red ]\x03",
            ':003A00011 PRIVMSG admin :Nueva funcionalidad',
            ':003A00011 PRIVMSG user :' . "\x033[ Noticias de la Red ]\x03",
            ':003A00011 PRIVMSG user :Nueva funcionalidad',
        ], $this->transport->written);
        self::assertSame(['001AAAAAA: Mensaje añadido.'], $this->irc->replies);
    }

    #[Test]
    public function motdDelRemovesByPosition(): void
    {
        $this->motdMessages->add('admin', 'Primera');
        $this->motdMessages->add('admin', 'Segunda');

        $this->handler->handle($this->privmsg('MOTD DEL 1', '001AAAAAA'), $this->state);

        self::assertCount(1, $this->motdMessages->all());
        self::assertSame('Segunda', $this->motdMessages->all()[0]['text']);
        self::assertStringContainsString('Borrada la noticia numero 1', $this->irc->replies[0]);
    }

    #[Test]
    public function motdDelRejectsInvalidPosition(): void
    {
        $this->handler->handle($this->privmsg('MOTD DEL 5', '001AAAAAA'), $this->state);

        self::assertSame(['001AAAAAA: ERROR: No se ha encontrado ninguna noticia en la posición 5.'], $this->irc->replies);
    }

    #[Test]
    public function motdListShowsItems(): void
    {
        $this->motdMessages->add('admin', 'Primera');

        $this->handler->handle($this->privmsg('MOTD LIST', '001AAAAAA'), $this->state);

        self::assertCount(3, $this->irc->replies);
        self::assertStringContainsString('Lista de noticias para usuarios:', $this->irc->replies[0]);
        self::assertStringContainsString('Fin de la lista.', $this->irc->replies[2]);
    }

    #[Test]
    public function motdListShowsEmptyMessage(): void
    {
        $this->handler->handle($this->privmsg('MOTD LIST', '001AAAAAA'), $this->state);

        self::assertSame(['001AAAAAA: Lista de noticias para usuarios vacía.'], $this->irc->replies);
    }

    #[Test]
    public function representantesAddBroadcastsToStaff(): void
    {
        $this->addUser('001CCCCCC', 'preoper', StaffRank::PreOperador);
        $this->addUser('001DDDDDD', 'oper', StaffRank::Operador);

        $this->handler->handle($this->privmsg('REPRESENTANTES ADD Aviso staff', '001AAAAAA'), $this->state);

        $nicks = $this->broadcastNicks();
        self::assertSame(['admin', 'preoper', 'oper'], $nicks);
        self::assertStringContainsString('[ Noticias para Representantes ]', $this->transport->written[0]);
    }

    #[Test]
    public function representantesAddRequiresAdmin(): void
    {
        $this->handler->handle($this->privmsg('REPRESENTANTES ADD Aviso', '001BBBBBB'), $this->state);

        self::assertSame(['001BBBBBB: Permiso denegado.'], $this->irc->replies);
    }

    #[Test]
    public function nickAddIntroducesPseudoClient(): void
    {
        $this->handler->handle($this->privmsg('NICK ADD Info Bienvenidos a la red', '001AAAAAA'), $this->state);

        $uidLine = $this->findUidLine('Info');
        self::assertNotNull($uidLine);

        $tempUid = $this->extractTempUid($uidLine);
        self::assertNotNull($tempUid);
        self::assertSame('Bienvenidos a la red', $this->motdNicks->get('Info'));
        self::assertSame(['001AAAAAA: Mensaje de entrada a la red mediante el nick Info activado.'], $this->irc->replies);
    }

    #[Test]
    public function nickAddRejectsRegisteredNick(): void
    {
        $this->accounts->save(new Account(new Nick('Info'), 'hash', null, new DateTimeImmutable()));

        $this->handler->handle($this->privmsg('NICK ADD Info Mensaje', '001AAAAAA'), $this->state);

        self::assertSame(['001AAAAAA: El nick Info está registrado.'], $this->irc->replies);
    }

    #[Test]
    public function nickAddRejectsNickInUse(): void
    {
        $this->state->introduceUser('001CCCCCC', 'Info', null, 'i', 'host.example');

        $this->handler->handle($this->privmsg('NICK ADD Info Mensaje', '001AAAAAA'), $this->state);

        self::assertSame(['001AAAAAA: El nick Info actualmente está en uso.'], $this->irc->replies);
    }

    #[Test]
    public function nickAddRejectsServiceNick(): void
    {
        $this->handler->handle($this->privmsg('NICK ADD NiCK Mensaje', '001AAAAAA'), $this->state);

        self::assertSame(['001AAAAAA: El nick NiCK es un bot de la red.'], $this->irc->replies);
    }

    #[Test]
    public function nickDelQuitsAndRemoves(): void
    {
        $this->motdNicks->add('Info', 'Bienvenidos');

        $this->handler->handle($this->privmsg('NICK DEL Info', '001AAAAAA'), $this->state);

        self::assertNull($this->motdNicks->get('Info'));
        self::assertCount(1, $this->transport->written);
        self::assertStringContainsString('QUIT :MOTD desactivado.', $this->transport->written[0]);
        self::assertSame(['001AAAAAA: Mensaje de entrada a la red mediante el nick Info borrado.'], $this->irc->replies);
    }

    #[Test]
    public function nickListShowsMappings(): void
    {
        $this->motdNicks->add('Info', 'Bienvenidos');

        $this->handler->handle($this->privmsg('NICK LIST', '001AAAAAA'), $this->state);

        self::assertCount(3, $this->irc->replies);
        self::assertStringContainsString('info: Bienvenidos', $this->irc->replies[1]);
    }

    #[Test]
    public function servidorAddStoresByServerName(): void
    {
        $this->state->registerServer('001', 'irc.sitiochat.es');
        $this->state->introduceUser('001EEEEEE', 'fromserver', null, 'i', 'host.example');

        $this->handler->handle($this->privmsg('SERVIDOR irc.sitiochat.es Bienvenidos por este servidor', '001AAAAAA'), $this->state);

        self::assertSame('Bienvenidos por este servidor', $this->serverMessages->get('irc.sitiochat.es'));
        self::assertSame(['001AAAAAA: Mensaje para los usuarios que conecten por el servidor irc.sitiochat.es añadido.'], $this->irc->replies);
    }

    #[Test]
    public function servidorAddStoresBySid(): void
    {
        $this->state->registerServer('001', 'irc.sitiochat.es');

        $this->handler->handle($this->privmsg('SERVIDOR 001 Bienvenidos', '001AAAAAA'), $this->state);

        self::assertSame('Bienvenidos', $this->serverMessages->get('irc.sitiochat.es'));
    }

    #[Test]
    public function servidorRemoveDeletesEntry(): void
    {
        $this->state->registerServer('001', 'irc.sitiochat.es');
        $this->serverMessages->set('irc.sitiochat.es', 'Bienvenidos');

        $this->handler->handle($this->privmsg('SERVIDOR 001', '001AAAAAA'), $this->state);

        self::assertNull($this->serverMessages->get('irc.sitiochat.es'));
        self::assertSame(['001AAAAAA: Mensaje para los usuarios que conecten por el servidor 001 quitado.'], $this->irc->replies);
    }

    #[Test]
    public function servidorRejectsUnknownServer(): void
    {
        $this->handler->handle($this->privmsg('SERVIDOR desconocido Mensaje', '001AAAAAA'), $this->state);

        self::assertSame(['001AAAAAA: El servidor desconocido no está conectado ahora mismo.'], $this->irc->replies);
    }

    #[Test]
    public function servidorRequiresAdmin(): void
    {
        $this->handler->handle($this->privmsg('SERVIDOR 001 Mensaje', '001BBBBBB'), $this->state);

        self::assertSame(['001BBBBBB: Permiso denegado.'], $this->irc->replies);
    }

    #[Test]
    public function noticiasRepliesWhenUrlIsConfigured(): void
    {
        $url = $this->createNewsFile('Últimas noticias de la red');
        $_ENV['DBOTS_NEWS_URL'] = $url;

        $this->handler->handle($this->privmsg('NOTICIAS', '001AAAAAA'), $this->state);

        self::assertSame(['001AAAAAA: Últimas noticias de la red'], $this->irc->replies);

        unset($_ENV['DBOTS_NEWS_URL']);
        @unlink($url);
    }

    #[Test]
    public function noticiasRepliesErrorWhenUrlNotConfigured(): void
    {
        unset($_ENV['DBOTS_NEWS_URL']);

        $this->handler->handle($this->privmsg('NOTICIAS', '001AAAAAA'), $this->state);

        self::assertSame(['001AAAAAA: URL de noticias no configurada.'], $this->irc->replies);
    }

    #[Test]
    public function noticiasRequiresAdmin(): void
    {
        $_ENV['DBOTS_NEWS_URL'] = 'http://localhost/nonexistent';

        $this->handler->handle($this->privmsg('NOTICIAS', '001BBBBBB'), $this->state);

        self::assertSame(['001BBBBBB: Permiso denegado.'], $this->irc->replies);
    }

    #[Test]
    public function helpRendersTheCommandCatalog(): void
    {
        $this->handler->handle($this->privmsg('HELP', '001AAAAAA'), $this->state);

        $output = implode("\n", $this->irc->replies);
        self::assertStringContainsString('Comandos de NoServ', $output);
        self::assertStringContainsString('MOTD', $output);
        self::assertStringContainsString('REPRESENTANTES', $output);
    }

    #[Test]
    public function helpRendersOneCommand(): void
    {
        $this->handler->handle($this->privmsg('HELP MOTD', '001AAAAAA'), $this->state);

        $output = implode("\n", $this->irc->replies);
        self::assertStringContainsString('MOTD', $output);
        self::assertStringContainsString('Nivel Administrador', $output);
    }

    #[Test]
    public function itIgnoresMessagesForAnotherService(): void
    {
        $this->handler->handle(new IrcMessage('001AAAAAA', 'PRIVMSG', ['NiCK'], 'ALTA'), $this->state);

        self::assertSame([], $this->irc->replies);
        self::assertSame([], $this->transport->written);
    }

    #[Test]
    public function itRepliesToUnknownCommands(): void
    {
        $this->handler->handle($this->privmsg('UNKNOWN', '001AAAAAA'), $this->state);

        self::assertSame(['001AAAAAA: Comando desconocido UNKNOWN. /msg NoServ AYUDA para ayuda.'], $this->irc->replies);
    }

    private function addUser(string $uid, string $nick, StaffRank $rank): void
    {
        $this->state->introduceUser($uid, $nick, $nick, 'r', 'host.example');
        $this->accounts->save(new Account(new Nick($nick), 'hash', null, new DateTimeImmutable()));
        $this->ranks->assign($nick, $rank);
    }

    private function privmsg(string $text, string $uid = '001AAAAAA'): IrcMessage
    {
        return new IrcMessage($uid, 'PRIVMSG', ['NoServ'], $text);
    }

    private function findUidLine(string $nick): ?string
    {
        foreach ($this->transport->written as $line) {
            if (str_contains($line, "UID {$nick}")) {
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
            if (1 === preg_match('/^:003A00011 PRIVMSG (\S+) :/', $line, $matches)) {
                $seen[$matches[1]] = true;
            }
        }

        return array_keys($seen);
    }

    private function createNewsFile(string $content): string
    {
        $path = sys_get_temp_dir() . '/dbots_news_' . uniqid() . '.txt';
        file_put_contents($path, $content);

        return 'file://' . $path;
    }
}
