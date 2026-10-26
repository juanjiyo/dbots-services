<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\Module\Sumidero;

use Dbots\Irc\Application\NetworkState;
use Dbots\Sumidero\Application\Config;
use Dbots\Sumidero\Application\SumideroService;
use Dbots\Tests\Support\InMemorySumideroStateRepository;
use Dbots\Tests\Support\RecordingEnforcement;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function count;
use function implode;
use function inet_pton;
use function preg_match;
use function sprintf;
use function str_contains;

final class SumideroModuleTest extends TestCase
{
    private int $clock;
    private InMemorySumideroStateRepository $repository;
    private RecordingEnforcement $enforcement;
    /** @var list<string> */
    private array $replies = [];
    /** @var list<string> */
    private array $lines = [];
    private SumideroService $service;
    private NetworkState $state;

    protected function setUp(): void
    {
        $this->clock = 1000;
        $this->repository = new InMemorySumideroStateRepository();
        $this->enforcement = new RecordingEnforcement();
        $this->replies = [];
        $this->lines = [];
        $this->state = new NetworkState();

        $config = $this->createConfig([
            'DBOTS_SUMIDERO_SINK' => '#Sumidero',
            'DBOTS_SUMIDERO_WATCHED' => '#SitioChat,#charla',
            'DBOTS_SUMIDERO_ADMINS' => 'JuanJo*',
            'DBOTS_SUMIDERO_GATE_ENABLED' => 'yes',
            'DBOTS_SUMIDERO_VALIDATE_SECONDS' => '30',
            'DBOTS_SUMIDERO_SHUN_TIME' => '15m',
            'DBOTS_SUMIDERO_SHUN_MAX' => '24h',
            'DBOTS_SUMIDERO_FLOOD_ENABLED' => 'yes',
            'DBOTS_SUMIDERO_FLOOD_MESSAGES' => '6',
            'DBOTS_SUMIDERO_FLOOD_WINDOW' => '8',
            'DBOTS_SUMIDERO_FLOOD_LENGTH' => '400',
            'DBOTS_SUMIDERO_FLOOD_REPEAT' => '3',
            'DBOTS_SUMIDERO_VERIFY_URL' => 'https://example.com/verify',
            'DBOTS_SUMIDERO_VERIFY_BRIDGE' => 'irc',
            'DBOTS_SUMIDERO_BRIDGE_MASK' => 'bridge-*@*',
            'DBOTS_SUMIDERO_TOKEN_LIFE' => '30m',
        ]);

        $this->service = new SumideroService(
            $config,
            $this->repository,
            $this->enforcement,
            '003A00100',
            function (string $target, string $text): void {
                $this->replies[] = sprintf('%s|%s', $target, $text);
            },
            function (string $line): void {
                $this->lines[] = $line;
            },
            fn (): int => $this->clock,
        );
    }

    #[Test]
    public function itJoinsTheSinkAndWatchedChannels(): void
    {
        $this->service->joinChannels();

        self::assertSame([
            ':003A00100 JOIN #Sumidero',
            ':003A00100 JOIN #SitioChat',
            ':003A00100 JOIN #charla',
        ], $this->lines);
    }

    #[Test]
    public function itSinksAnUnidentifiedUserAfterTheGateDelay(): void
    {
        $this->introduceUser('001AAAAAA', 'Spammer', 'spammer.example.com');

        $this->service->onUserIntroduced('001AAAAAA', $this->state);
        $this->service->checkGate('001AAAAAA', $this->state);

        self::assertCount(1, $this->enforcement->svsJoins);
        self::assertCount(1, $this->enforcement->shuns);
        self::assertStringContainsString('*@spammer.example.com', $this->enforcement->shuns[0]);
    }

    #[Test]
    public function itDoesNotSinkAnIdentifiedUser(): void
    {
        $this->introduceUser('001AAAAAA', 'GoodUser', 'good.example.com', 'r');

        $this->service->onUserIntroduced('001AAAAAA', $this->state);
        $this->service->checkGate('001AAAAAA', $this->state);

        self::assertCount(0, $this->enforcement->svsJoins);
        self::assertCount(0, $this->enforcement->shuns);
    }

    #[Test]
    public function itDoesNotSinkAnAdmin(): void
    {
        $this->introduceUser('001AAAAAA', 'JuanJo_Jaen', 'admin.example.com');

        $this->service->checkGate('001AAAAAA', $this->state);

        self::assertCount(0, $this->enforcement->shuns);
    }

    #[Test]
    public function itDoesNotSinkAnIgnoredUser(): void
    {
        $this->repository->addIgnored('spammer.example.com');
        $this->introduceUser('001AAAAAA', 'Spammer', 'spammer.example.com');

        $this->service->checkGate('001AAAAAA', $this->state);

        self::assertCount(0, $this->enforcement->shuns);
    }

    #[Test]
    public function itSinksAFlooderInAWatchedChannel(): void
    {
        $this->introduceUser('001AAAAAA', 'Flooder', 'flood.example.com');

        for ($i = 0; $i < 7; ++$i) {
            $this->service->onChannelMessage('#SitioChat', '001AAAAAA', 'hola', $this->state);
        }

        self::assertCount(1, $this->enforcement->shuns);
        self::assertStringContainsString('flood', $this->enforcement->shuns[0]);
    }

    #[Test]
    public function itSinksAUserForRepeatedText(): void
    {
        $this->introduceUser('001AAAAAA', 'Repeater', 'repeat.example.com');

        for ($i = 0; $i < 3; ++$i) {
            $this->service->onChannelMessage('#charla', '001AAAAAA', 'same text', $this->state);
        }

        self::assertCount(1, $this->enforcement->shuns);
    }

    #[Test]
    public function itSinksAUserForALongMessage(): void
    {
        $this->introduceUser('001AAAAAA', 'Long', 'long.example.com');

        $this->service->onChannelMessage('#SitioChat', '001AAAAAA', str_repeat('a', 400), $this->state);

        self::assertCount(1, $this->enforcement->shuns);
    }

    #[Test]
    public function itEscalatesShunDurationForRepeatOffenders(): void
    {
        $this->introduceUser('001AAAAAA', 'Repeat', 'host.example.com');
        $this->service->sink('001AAAAAA', 'test', $this->state);

        $first = $this->enforcement->shuns[0];

        $this->service->release('001AAAAAA', $this->state);
        $this->service->sink('001AAAAAA', 'test', $this->state);

        $second = $this->enforcement->shuns[1];

        self::assertStringContainsString('|900|', $first);
        self::assertStringContainsString('|1800|', $second);
    }

    #[Test]
    public function itRespectsTheMaximumShunDuration(): void
    {
        $this->introduceUser('001AAAAAA', 'Repeat', 'host.example.com');

        for ($i = 0; $i < 96; ++$i) {
            $this->repository->bumpOffense('host.example.com');
        }

        $this->service->sink('001AAAAAA', 'test', $this->state);

        $last = $this->enforcement->shuns[count($this->enforcement->shuns) - 1];

        self::assertStringContainsString('|86400|', $last);
    }

    #[Test]
    public function itSinksAUserMatchingASpamPattern(): void
    {
        $this->repository->addSpamPattern('spam offer');
        $this->introduceUser('001AAAAAA', 'Spammer', 'spam.example.com');

        $this->service->onChannelMessage('#SitioChat', '001AAAAAA', 'great spam offer here', $this->state);

        self::assertCount(1, $this->enforcement->shuns);
    }

    #[Test]
    public function itReleasesAUserThroughTheVerificationBridge(): void
    {
        $this->introduceUser('001AAAAAA', 'Victim', 'victim.example.com');
        $this->service->sink('001AAAAAA', 'test', $this->state);

        $this->introduceUser('001BBBBBB', 'bridge-foo', 'web.example.com');

        $token = $this->findTokenInReplies('001AAAAAA');
        self::assertNotNull($token);

        $this->service->onPrivateMessage('001BBBBBB', sprintf('VERIFICAR %s 1.2.3.4', $token), $this->state);

        self::assertCount(1, $this->enforcement->unshuns);
    }

    #[Test]
    public function itRejectsVerificationFromANonBridgeMask(): void
    {
        $this->introduceUser('001AAAAAA', 'Victim', 'victim.example.com');
        $this->service->sink('001AAAAAA', 'test', $this->state);

        $this->introduceUser('001BBBBBB', 'Random', 'other.example.com');

        $token = $this->findTokenInReplies('001AAAAAA');
        self::assertNotNull($token);

        $this->service->onPrivateMessage('001BBBBBB', sprintf('VERIFICAR %s 1.2.3.4', $token), $this->state);

        self::assertCount(0, $this->enforcement->unshuns);
    }

    #[Test]
    public function itRejectsVerificationWithWrongIp(): void
    {
        $this->introduceUser('001AAAAAA', 'Victim', 'victim.example.com', '', $this->encodeIp('1.2.3.4'));
        $this->service->sink('001AAAAAA', 'test', $this->state);

        $this->introduceUser('001BBBBBB', 'bridge-foo', 'web.example.com');

        $token = $this->findTokenInReplies('001AAAAAA');
        self::assertNotNull($token);

        $this->service->onPrivateMessage('001BBBBBB', sprintf('VERIFICAR %s 5.6.7.8', $token), $this->state);

        self::assertCount(0, $this->enforcement->unshuns);
    }

    #[Test]
    public function itRejectsExpiredVerificationTokens(): void
    {
        $this->introduceUser('001AAAAAA', 'Victim', 'victim.example.com');
        $this->service->sink('001AAAAAA', 'test', $this->state);

        $this->introduceUser('001BBBBBB', 'bridge-foo', 'web.example.com');

        $token = $this->findTokenInReplies('001AAAAAA');
        self::assertNotNull($token);

        $this->clock += 2000;

        $this->service->onPrivateMessage('001BBBBBB', sprintf('VERIFICAR %s 1.2.3.4', $token), $this->state);

        self::assertCount(0, $this->enforcement->unshuns);
    }

    #[Test]
    public function itAllowsAStaffMemberToReleaseAUser(): void
    {
        $this->introduceUser('001AAAAAA', 'Victim', 'victim.example.com');
        $this->service->sink('001AAAAAA', 'test', $this->state);

        $this->introduceUser('001BBBBBB', 'JuanJo_Jaen', 'admin.example.com');
        $this->service->onPrivateMessage('001BBBBBB', 'release Victim', $this->state);

        self::assertCount(1, $this->enforcement->unshuns);
    }

    #[Test]
    public function itRejectsStaffCommandsFromUnauthorizedUsers(): void
    {
        $this->introduceUser('001AAAAAA', 'Random', 'random.example.com');
        $this->service->onPrivateMessage('001AAAAAA', 'status', $this->state);

        self::assertSame([], $this->replies);
    }

    #[Test]
    public function itAddsListsAndRemovesSpamPatterns(): void
    {
        $this->introduceUser('001AAAAAA', 'JuanJo_Jaen', 'admin.example.com');

        $this->service->onPrivateMessage('001AAAAAA', 'spam add free money', $this->state);
        $this->service->onPrivateMessage('001AAAAAA', 'spam list', $this->state);

        self::assertStringContainsString('Añadido [1]: free money', implode("\n", $this->replies));
        self::assertStringContainsString('1: free money', implode("\n", $this->replies));
    }

    #[Test]
    public function itAddsAndRemovesIgnoredPatterns(): void
    {
        $this->introduceUser('001AAAAAA', 'JuanJo_Jaen', 'admin.example.com');

        $this->service->onPrivateMessage('001AAAAAA', 'ignore bot@example.com', $this->state);
        $this->service->onPrivateMessage('001AAAAAA', 'unignore bot@example.com', $this->state);

        self::assertSame([], $this->repository->allIgnored());
    }

    #[Test]
    public function itShowsStatusToStaff(): void
    {
        $this->introduceUser('001AAAAAA', 'JuanJo_Jaen', 'admin.example.com');

        $this->service->onPrivateMessage('001AAAAAA', 'status', $this->state);

        self::assertStringContainsString('activo 0h 0m', implode("\n", $this->replies));
    }

    /**
     * @param array<string, string> $env
     */
    private function createConfig(array $env): Config
    {
        foreach ($env as $key => $value) {
            $_ENV[$key] = $value;
        }

        return new Config();
    }

    private function introduceUser(string $uid, string $nick, string $host, string $modes = '', ?string $ip = null): void
    {
        $this->state->introduceUser($uid, $nick, null, $modes, $host, $ip);
    }

    private function encodeIp(string $ip): string
    {
        $binary = inet_pton($ip);

        return false === $binary ? '' : base64_encode($binary);
    }

    private function findTokenInReplies(string $uid): ?string
    {
        foreach ($this->replies as $reply) {
            if (!str_contains($reply, $uid)) {
                continue;
            }

            if (preg_match('/\?t=([a-f0-9]+)/', $reply, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }
}
