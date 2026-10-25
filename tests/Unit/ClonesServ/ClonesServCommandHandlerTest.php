<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\ClonesServ;

use DateTimeImmutable;
use Dbots\ClonesServ\Adapter\In\Irc\ClonesServCommandHandler;
use Dbots\Domain\Account\Account;
use Dbots\Domain\Account\Nick;
use Dbots\Domain\Account\Password;
use Dbots\Domain\Staff\StaffRank;
use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\AccountResolver;
use Dbots\Irc\Application\NetworkState;
use Dbots\Staff\Application\StaffAuthority;
use Dbots\Tests\Support\InMemoryAccountRepository;
use Dbots\Tests\Support\InMemoryClonesExceptionRepository;
use Dbots\Tests\Support\InMemoryStaffRankRepository;
use Dbots\Tests\Support\RecordingClonesWriter;
use Dbots\Tests\Support\RecordingIrc;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClonesServCommandHandlerTest extends TestCase
{
    private InMemoryClonesExceptionRepository $repository;
    private RecordingClonesWriter $writer;
    private RecordingIrc $irc;
    private ClonesServCommandHandler $handler;
    private NetworkState $state;
    private InMemoryAccountRepository $accounts;
    private InMemoryStaffRankRepository $ranks;
    private StaffAuthority $staff;

    protected function setUp(): void
    {
        $this->repository = new InMemoryClonesExceptionRepository();
        $this->writer = new RecordingClonesWriter();
        $this->irc = new RecordingIrc();
        $this->accounts = new InMemoryAccountRepository();
        $this->ranks = new InMemoryStaffRankRepository();
        $this->staff = new StaffAuthority($this->ranks);

        $this->handler = new ClonesServCommandHandler(
            $this->repository,
            $this->writer,
            $this->staff,
            new AccountResolver($this->accounts),
            $this->irc,
            'CLoNeS',
            '003A00009',
        );

        $this->state = new NetworkState();
        $this->state->introduceUser('001AAAAAA', 'admin', null, 'r', 'staff.example');
        $this->accounts->save(Account::register(new Nick('admin'), Password::fromPlain('secreto'), null, new DateTimeImmutable()));
        $this->ranks->assign('admin', StaffRank::Administrador);
    }

    #[Test]
    public function anAdminCanAddAPermanentException(): void
    {
        $this->handler->handle($this->privmsg('PERM 1.2.3.4 10'), $this->state);

        $stored = $this->repository->findByHost('1.2.3.4');
        self::assertNotNull($stored);
        self::assertSame(10, $stored->clones);
        self::assertSame('perm', $stored->type);
        self::assertNull($stored->expiresAt);
        self::assertCount(1, $this->writer->writes);
        self::assertStringContainsString('Entrada PERM añadida para 1.2.3.4 (10 clones)', implode(' ', $this->irc->replies));
    }

    #[Test]
    public function anAdminCanAddATemporaryException(): void
    {
        $this->handler->handle($this->privmsg('ADD 1.2.3.4 10 30d'), $this->state);

        $stored = $this->repository->findByHost('1.2.3.4');
        self::assertNotNull($stored);
        self::assertSame(10, $stored->clones);
        self::assertSame('temp', $stored->type);
        self::assertNotNull($stored->expiresAt);
        self::assertCount(1, $this->writer->writes);
        self::assertStringContainsString('Entrada TEMP añadida para 1.2.3.4 (10 clones)', implode(' ', $this->irc->replies));
    }

    #[Test]
    public function itRejectsDuplicateHosts(): void
    {
        $this->handler->handle($this->privmsg('PERM 1.2.3.4 10'), $this->state);
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('PERM 1.2.3.4 20'), $this->state);

        self::assertCount(1, $this->repository->all());
        self::assertStringContainsString('Ya existe una entrada para 1.2.3.4', implode(' ', $this->irc->replies));
    }

    #[Test]
    public function itRejectsInvalidHosts(): void
    {
        $this->handler->handle($this->privmsg('PERM 1.2.3.4! 10'), $this->state);

        self::assertNull($this->repository->findByHost('1.2.3.4!'));
        self::assertStringContainsString('IP/Host no válido', implode(' ', $this->irc->replies));
    }

    #[Test]
    public function itRejectsInvalidCloneCounts(): void
    {
        $this->handler->handle($this->privmsg('PERM 1.2.3.4 0'), $this->state);

        self::assertNull($this->repository->findByHost('1.2.3.4'));
        self::assertStringContainsString('Número de clones no válido', implode(' ', $this->irc->replies));
    }

    #[Test]
    public function itRejectsInvalidDurations(): void
    {
        $this->handler->handle($this->privmsg('ADD 1.2.3.4 10 forever'), $this->state);

        self::assertNull($this->repository->findByHost('1.2.3.4'));
        self::assertStringContainsString('Formato de tiempo no válido', implode(' ', $this->irc->replies));
    }

    #[Test]
    public function anAdminCanRenewAnException(): void
    {
        $this->handler->handle($this->privmsg('ADD 1.2.3.4 10 30d'), $this->state);
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('RENEW 1.2.3.4 6m'), $this->state);

        $stored = $this->repository->findByHost('1.2.3.4');
        self::assertNotNull($stored);
        self::assertSame(10, $stored->clones);
        self::assertNotNull($stored->expiresAt);
        self::assertStringContainsString('Entrada renovada para 1.2.3.4', implode(' ', $this->irc->replies));
        self::assertCount(2, $this->writer->writes);
    }

    #[Test]
    public function anAdminCanEditAnException(): void
    {
        $this->handler->handle($this->privmsg('ADD 1.2.3.4 10 30d'), $this->state);
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('EDIT 1.2.3.4 20 1y'), $this->state);

        $stored = $this->repository->findByHost('1.2.3.4');
        self::assertNotNull($stored);
        self::assertSame(20, $stored->clones);
        self::assertNotNull($stored->expiresAt);
        self::assertStringContainsString('Entrada editada para 1.2.3.4 (20 clones', implode(' ', $this->irc->replies));
        self::assertCount(2, $this->writer->writes);
    }

    #[Test]
    public function anAdminCanDeleteAnException(): void
    {
        $this->handler->handle($this->privmsg('PERM 1.2.3.4 10'), $this->state);
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('DEL 1.2.3.4'), $this->state);

        self::assertNull($this->repository->findByHost('1.2.3.4'));
        self::assertStringContainsString('Entrada eliminada para 1.2.3.4', implode(' ', $this->irc->replies));
        self::assertCount(2, $this->writer->writes);
    }

    #[Test]
    public function infoShowsTheRequestedHost(): void
    {
        $this->handler->handle($this->privmsg('PERM 1.2.3.4 10'), $this->state);
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('INFO 1.2.3.4'), $this->state);

        $reply = implode(' ', $this->irc->replies);
        self::assertStringContainsString('Información de la iline', $reply);
        self::assertStringContainsString('IP:', $reply);
        self::assertStringContainsString('Clones:', $reply);
        self::assertStringContainsString('Expiración:', $reply);
        self::assertStringContainsString('Permanente', $reply);
    }

    #[Test]
    public function infoWithoutArgUsesTheNickmap(): void
    {
        $this->handler->handle($this->privmsg('PERM 1.2.3.4 10'), $this->state);
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('INFO'), $this->state);

        self::assertStringContainsString('1.2.3.4', implode(' ', $this->irc->replies));
    }

    #[Test]
    public function infoWithoutArgComplainsWhenThereIsNoMapping(): void
    {
        $this->handler->handle($this->privmsg('INFO'), $this->state);

        self::assertStringContainsString('No tienes una IP asignada', implode(' ', $this->irc->replies));
    }

    #[Test]
    public function listShowsActiveEntries(): void
    {
        $this->handler->handle($this->privmsg('PERM 1.2.3.4 10'), $this->state);
        $this->handler->handle($this->privmsg('ADD 5.6.7.8 5 30d'), $this->state);
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('LIST'), $this->state);

        $reply = implode(' ', $this->irc->replies);
        self::assertStringContainsString('Lista de ilines activas', $reply);
        self::assertStringContainsString('1.2.3.4', $reply);
        self::assertStringContainsString('5.6.7.8', $reply);
    }

    #[Test]
    public function listSaysWhenThereAreNoEntries(): void
    {
        $this->handler->handle($this->privmsg('LIST'), $this->state);

        self::assertStringContainsString('No hay entradas', implode(' ', $this->irc->replies));
    }

    #[Test]
    public function aNonAdminCannotMutate(): void
    {
        $this->state->introduceUser('001BBBBBB', 'user', null, 'i', 'user.example');

        $this->handler->handle($this->privmsg('PERM 1.2.3.4 10', '001BBBBBB'), $this->state);

        self::assertNull($this->repository->findByHost('1.2.3.4'));
        self::assertStringContainsString('Ese comando es para la administración', implode(' ', $this->irc->replies));
    }

    #[Test]
    public function itWarnsWhenTheIrcdPidFileIsMissing(): void
    {
        $this->writer->rehashOk = false;

        $this->handler->handle($this->privmsg('PERM 1.2.3.4 10'), $this->state);

        self::assertNotNull($this->repository->findByHost('1.2.3.4'));
        self::assertStringContainsString('no se encontró el PID de InspIRCd', implode(' ', $this->irc->replies));
    }

    #[Test]
    public function cleanupRemovesExpiredEntries(): void
    {
        $this->handler->handle($this->privmsg('ADD 1.2.3.4 10 1d'), $this->state);
        $this->handler->handle($this->privmsg('PERM 5.6.7.8 5'), $this->state);

        $expired = new \Dbots\ClonesServ\Domain\ClonesException(
            null,
            '9.9.9.9',
            3,
            'temp',
            'admin',
            new DateTimeImmutable('-2 days'),
            new DateTimeImmutable('-1 day'),
        );
        $this->repository->save($expired);

        $this->handler->cleanup();

        self::assertNull($this->repository->findByHost('9.9.9.9'));
        self::assertNotNull($this->repository->findByHost('1.2.3.4'));
        self::assertNotNull($this->repository->findByHost('5.6.7.8'));
        self::assertCount(3, $this->writer->writes);
    }

    #[Test]
    public function itIgnoresMessagesForAnotherService(): void
    {
        $this->handler->handle(new IrcMessage('001AAAAAA', 'PRIVMSG', ['OPeR'], 'PERM 1.2.3.4 10'), $this->state);

        self::assertNull($this->repository->findByHost('1.2.3.4'));
    }

    private function privmsg(string $text, string $uid = '001AAAAAA'): IrcMessage
    {
        return new IrcMessage($uid, 'PRIVMSG', ['CLoNeS'], $text);
    }
}
