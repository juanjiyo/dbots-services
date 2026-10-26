<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\Module;

use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\NetworkState;
use Dbots\Module\ModuleContext;
use Dbots\Tests\Support\RecordingEnforcement;
use Dbots\Tests\Support\RecordingTransport;
use Modules\Ping\PingModule;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PingModuleTest extends TestCase
{
    private RecordingTransport $transport;
    private PingModule $module;
    private ModuleContext $context;
    private NetworkState $state;

    protected function setUp(): void
    {
        $this->transport = new RecordingTransport();
        $this->module = new PingModule();
        $this->context = new ModuleContext(
            '003A00100',
            '003',
            $this->transport,
            new RecordingEnforcement(),
            new PDO('sqlite::memory:'),
            static fn (): null => null,
        );
        $this->state = new NetworkState();
    }

    #[Test]
    public function itAnswersPingAddressedToItsNick(): void
    {
        $this->module->handle(new IrcMessage('001AAAAAA', 'PRIVMSG', ['PiNG'], 'hola'), $this->state, $this->context);

        self::assertSame([':003A00100 NOTICE 001AAAAAA :PONG HOLA'], $this->transport->written);
    }

    #[Test]
    public function itAlsoAnswersWhenTheIrcDeliversTheUid(): void
    {
        $this->module->handle(new IrcMessage('001AAAAAA', 'PRIVMSG', ['003A00100'], 'eco'), $this->state, $this->context);

        self::assertSame([':003A00100 NOTICE 001AAAAAA :PONG ECO'], $this->transport->written);
    }

    #[Test]
    public function itIgnoresTrafficForOthers(): void
    {
        $this->module->handle(new IrcMessage('001AAAAAA', 'PRIVMSG', ['NiCK'], 'hola'), $this->state, $this->context);

        self::assertSame([], $this->transport->written);
    }
}
