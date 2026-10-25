<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\CRegServ;

use DateTimeImmutable;
use Dbots\CRegServ\Adapter\In\Irc\CRegServCommandHandler;
use Dbots\CRegServ\Application\Approve\ApproveHandler;
use Dbots\CRegServ\Application\Cancel\CancelHandler;
use Dbots\CRegServ\Application\Deny\DenyHandler;
use Dbots\CRegServ\Application\Help\CRegServHelp;
use Dbots\CRegServ\Application\Request\RequestRegistrationHandler;
use Dbots\Domain\Account\Account;
use Dbots\Domain\Account\Nick;
use Dbots\Domain\Channel\ChannelName;
use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\AccountResolver;
use Dbots\Irc\Application\NetworkState;
use Dbots\Tests\Support\InMemoryAccountRepository;
use Dbots\Tests\Support\InMemoryChannelRepository;
use Dbots\Tests\Support\RecordingChannelProjection;
use Dbots\Tests\Support\RecordingIrc;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CRegServCommandHandlerTest extends TestCase
{
    private InMemoryChannelRepository $channels;
    private InMemoryChannelRequestRepository $requests;
    private RecordingIrc $irc;
    private CRegServCommandHandler $handler;
    private NetworkState $state;

    protected function setUp(): void
    {
        $accounts = new InMemoryAccountRepository();
        $accounts->save(new Account(new Nick('alice'), 'hash', null, new DateTimeImmutable()));
        $accounts->save(new Account(new Nick('staff'), 'hash', null, new DateTimeImmutable()));

        $this->channels = new InMemoryChannelRepository();
        $this->requests = new InMemoryChannelRequestRepository();
        $this->irc = new RecordingIrc();

        $this->handler = new CRegServCommandHandler(
            new RequestRegistrationHandler($this->requests, $this->channels),
            new ApproveHandler($this->requests, $this->channels, new RecordingChannelProjection()),
            new DenyHandler($this->requests),
            new CancelHandler($this->requests),
            $this->requests,
            $this->channels,
            new RecordingChannelProjection(),
            new AccountResolver($accounts),
            $this->irc,
            'CReG',
            '003A00006',
            CRegServHelp::create(),
        );

        $this->state = new NetworkState();
        $this->state->introduceUser('001AAAAAA', 'alice', null, 'r');
        $this->state->introduceUser('001BBBBBB', 'staff', null, 'ro');
    }

    #[Test]
    public function aChannelOperatorCanRequestARegistration(): void
    {
        $this->state->joinChannel('#pedido', ['001AAAAAA'], 'o');

        $this->handler->handle($this->privmsg('REGISTRA #pedido mi canal'), $this->state);

        self::assertStringContainsString('Solicitud registrada', $this->irc->replies[0]);
        self::assertTrue($this->requests->find('#pedido')?->isWaiting());
    }

    #[Test]
    public function aRequesterWithoutOperatorStatusIsRefused(): void
    {
        $this->state->joinChannel('#pedido', ['001AAAAAA'], 'v');

        $this->handler->handle($this->privmsg('REGISTRA #pedido'), $this->state);

        self::assertStringContainsString('@ en #pedido', $this->irc->replies[0]);
        self::assertNull($this->requests->find('#pedido'));
    }

    #[Test]
    public function staffApproveAndTheChannelIsRegistered(): void
    {
        $this->state->joinChannel('#pedido', ['001AAAAAA'], 'o');
        $this->handler->handle($this->privmsg('REGISTRA #pedido'), $this->state);
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('ACEPTA #pedido', '001BBBBBB'), $this->state);

        self::assertSame(['001BBBBBB: #pedido registrado.'], $this->irc->replies);
        self::assertSame('alice', $this->channels->find(new ChannelName('#pedido'))?->founder);
    }

    #[Test]
    public function aNormalUserCannotApprove(): void
    {
        $this->state->joinChannel('#pedido', ['001AAAAAA'], 'o');
        $this->handler->handle($this->privmsg('REGISTRA #pedido'), $this->state);
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('ACEPTA #pedido'), $this->state);

        self::assertSame(['001AAAAAA: Ese comando es para el staff.'], $this->irc->replies);
    }

    #[Test]
    public function denyingKeepsTheReason(): void
    {
        $this->state->joinChannel('#pedido', ['001AAAAAA'], 'o');
        $this->handler->handle($this->privmsg('REGISTRA #pedido'), $this->state);
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('DENIEGA #pedido no cumple', '001BBBBBB'), $this->state);

        self::assertStringContainsString('denegada', $this->irc->replies[0]);
        self::assertSame('no cumple', $this->requests->find('#pedido')?->reason);
    }

    #[Test]
    public function aRegisteredChannelCannotBeRequestedAgain(): void
    {
        $this->channels->seed('#tomado', 'alice');
        $this->state->joinChannel('#tomado', ['001AAAAAA'], 'o');

        $this->handler->handle($this->privmsg('REGISTRA #tomado'), $this->state);

        self::assertStringContainsString('ya está registrado', $this->irc->replies[0]);
    }

    #[Test]
    public function theRequesterCanCancelTheirOwnRequest(): void
    {
        $this->state->joinChannel('#pedido', ['001AAAAAA'], 'o');
        $this->handler->handle($this->privmsg('REGISTRA #pedido'), $this->state);
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('CANCELA #pedido'), $this->state);

        self::assertStringContainsString('retirada', $this->irc->replies[0]);
    }

    #[Test]
    public function statusIsVisibleToAnyone(): void
    {
        $this->state->joinChannel('#pedido', ['001AAAAAA'], 'o');
        $this->handler->handle($this->privmsg('REGISTRA #pedido'), $this->state);
        $this->irc->replies = [];

        $this->handler->handle($this->privmsg('ESTADO #pedido'), $this->state);

        self::assertStringContainsString('PENDIENTE', $this->irc->replies[0]);
    }

    #[Test]
    public function staffCanRegisterAnOfficialChannel(): void
    {
        $this->handler->handle($this->privmsg('OFICIAL #oficial canal de la red', '001BBBBBB'), $this->state);

        self::assertNotNull($this->channels->find(new ChannelName('#oficial')));
        self::assertStringContainsString('OFICIAL', $this->irc->replies[0]);
    }

    private function privmsg(string $text, string $uid = '001AAAAAA'): IrcMessage
    {
        return new IrcMessage($uid, 'PRIVMSG', ['CReG'], $text);
    }
}

final class InMemoryChannelRequestRepository implements \Dbots\CRegServ\Application\Port\Out\ChannelRequestRepositoryInterface
{
    /** @var array<string, \Dbots\Domain\Registration\ChannelRequest> */
    private array $requests = [];

    /** @var list<string> */
    private array $admins = [];

    public function find(string $channel): ?\Dbots\Domain\Registration\ChannelRequest
    {
        return $this->requests[$channel] ?? null;
    }

    public function save(\Dbots\Domain\Registration\ChannelRequest $request): void
    {
        $this->requests[$request->channel] = $request;
    }

    public function delete(string $channel): void
    {
        unset($this->requests[$channel]);
    }

    public function byStatus(\Dbots\Domain\Registration\RequestStatus $status): array
    {
        return array_values(array_filter($this->requests, static fn ($r): bool => $r->status === $status));
    }

    public function all(): array
    {
        return array_values($this->requests);
    }


    public function addAdmin(string $nick): void
    {
        $this->admins[] = $nick;
    }

    public function removeAdmin(string $nick): bool
    {
        $before = count($this->admins);
        $this->admins = array_values(array_filter($this->admins, static fn (string $a): bool => $a !== $nick));

        return $before !== count($this->admins);
    }

    public function isAdmin(string $nick): bool
    {
        return in_array($nick, $this->admins, true);
    }

    public function admins(): array
    {
        return $this->admins;
    }
}
