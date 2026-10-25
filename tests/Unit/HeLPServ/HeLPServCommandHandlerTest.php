<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\HeLPServ;

use Dbots\HeLPServ\Adapter\In\Irc\HeLPServCommandHandler;
use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\Help\IrcFormat;
use Dbots\Irc\Application\NetworkState;
use Dbots\Tests\Support\RecordingIrc;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HeLPServCommandHandlerTest extends TestCase
{
    private RecordingIrc $irc;
    private HeLPServCommandHandler $handler;
    private NetworkState $state;

    protected function setUp(): void
    {
        $this->irc = new RecordingIrc();
        $this->handler = new HeLPServCommandHandler(
            $this->irc,
            'HeLP',
            '003A00007',
            'NiCK',
            'CReG',
            'CHaN',
            ['NiCK' => 'Registro y mantenimiento de nicks.', 'CReG' => 'Registro de canales.'],
        );

        $this->state = new NetworkState();
        $this->state->introduceUser('001AAAAAA', 'alice', null, '');
    }

    #[Test]
    public function itServesTheIndexWhenAskedForHelp(): void
    {
        $this->handler->handle($this->privmsg('AYUDA'), $this->state);

        $plain = $this->plain();

        self::assertStringContainsString('[ Ayuda de HeLP ]', $plain[0]);
        self::assertStringContainsString('UMODES', implode("\n", $plain));
        self::assertStringContainsString('CANAL', implode("\n", $plain));
    }

    #[Test]
    public function aTopicIsTheSameAsHelpWithThatTopic(): void
    {
        $this->handler->handle($this->privmsg('AYUDA UMODES'), $this->state);
        $withPrefix = $this->plain();

        $this->irc->replies = [];
        $this->handler->handle($this->privmsg('UMODES'), $this->state);

        self::assertSame($withPrefix, $this->plain());
        self::assertStringContainsString('Modos disponibles para usuarios:', $withPrefix[0]);
    }

    #[Test]
    public function itListsTheChannelModes(): void
    {
        $this->handler->handle($this->privmsg('CMODES'), $this->state);

        $plain = implode("\n", $this->plain());

        self::assertStringContainsString('Modos de usuario dentro del canal:', $plain);
        self::assertStringContainsString('Status de fundador del canal.', $plain);
    }

    #[Test]
    public function itListsTheBotsItWasGiven(): void
    {
        $this->handler->handle($this->privmsg('BOTS'), $this->state);

        $plain = implode("\n", $this->plain());

        self::assertStringContainsString('NiCK Registro y mantenimiento de nicks.', $plain);
        self::assertStringContainsString('CReG Registro de canales.', $plain);
        self::assertStringNotContainsString('HeLP Ayuda global', $plain);
    }

    #[Test]
    public function theChannelTopicPointsAtCRegWithThePassword(): void
    {
        $this->handler->handle($this->privmsg('CANAL'), $this->state);

        $plain = implode("\n", $this->plain());

        self::assertStringContainsString('/msg CReG REGISTRA <canal> <contraseña> <descripción>', $plain);
        self::assertStringContainsString('/msg CHaN IDENTIFY <canal> <contraseña>', $plain);
    }

    #[Test]
    public function theNickTopicPointsAtNiCK(): void
    {
        $this->handler->handle($this->privmsg('NICK'), $this->state);

        self::assertStringContainsString('/msg NiCK REGISTRA <contraseña>', implode("\n", $this->plain()));
    }

    #[Test]
    public function creditsKeepTheDbotsAuthors(): void
    {
        $this->handler->handle($this->privmsg('CREDITOS'), $this->state);

        $plain = implode("\n", $this->plain());

        self::assertStringContainsString('Davidlig', $plain);
        self::assertStringContainsString('Mew', $plain);
    }

    #[Test]
    public function anUnknownTopicGetsTheOriginalAnswer(): void
    {
        $this->handler->handle($this->privmsg('NOEXISTE'), $this->state);

        self::assertSame(
            ['001AAAAAA: Comando desconocido NOEXISTE. /msg HeLP AYUDA para ayuda.'],
            $this->plain(),
        );
    }

    #[Test]
    public function itIgnoresMessagesForAnotherService(): void
    {
        $message = new IrcMessage('001AAAAAA', 'PRIVMSG', ['CReG'], 'AYUDA');

        $this->handler->handle($message, $this->state);

        self::assertSame([], $this->irc->replies);
    }

    /** @return list<string> */
    private function plain(): array
    {
        return array_map(
            static fn (string $line): string => IrcFormat::strip($line),
            $this->irc->replies,
        );
    }

    private function privmsg(string $text): IrcMessage
    {
        return new IrcMessage('001AAAAAA', 'PRIVMSG', ['HeLP'], $text);
    }
}
