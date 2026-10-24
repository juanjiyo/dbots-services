<?php

declare(strict_types=1);

namespace Dbots;

use Dbots\Adapter\Out\Transport\TlsStreamTransport;
use Dbots\Application\Daemon;
use Dbots\Application\Link\LinkRunner;
use Dbots\Domain\Link\ServerLink;
use Dbots\Irc\Adapter\In\IrcMessageParser;
use Dbots\Irc\Adapter\Out\Irc\ServiceUidFormatter;
use Dbots\Irc\Application\NetworkState;
use Dbots\NickServ\Adapter\In\Irc\NickServCommandHandler;
use Dbots\NickServ\Adapter\Out\Irc\IrcReplySender;
use Dbots\NickServ\Adapter\Out\Irc\S2sAccountLogin;
use Dbots\NickServ\Adapter\Out\Persistence\SqliteAccountRepository;
use Dbots\NickServ\Application\Identify\IdentifyHandler;
use Dbots\NickServ\Application\RegisterNick\RegisterNickHandler;
use Dbots\Udb\AccountBlockProvider;
use Dbots\Udb\UdbAccountProjection;
use Dbots\Udb\UdbAuthority;

use function dirname;
use function is_dir;
use function mkdir;
use function sprintf;

/**
 * Manual wiring. Deliberately no DI container yet: there are a dozen objects
 * and a container would hide more than it explains. Swap it in when the graph
 * hurts.
 */
final readonly class Kernel
{
    private function __construct(private Daemon $daemon) {}

    public static function create(
        ServerLink $link,
        string $host,
        int $port,
        string $databasePath,
        bool $verifyPeer = true,
        int $syncTimeoutSeconds = 30,
        ?string $udbEpoch = null,
    ): self {
        $transport = new TlsStreamTransport($host, $port, verifyPeer: $verifyPeer);
        $runner = new LinkRunner($link, $transport, $syncTimeoutSeconds);

        if (!is_dir(dirname($databasePath))) {
            mkdir(dirname($databasePath), 0o775, true);
        }

        $repository = SqliteAccountRepository::fromPath($databasePath);
        $serviceUid = sprintf('%sA00001', $link->sid);

        $authority = new UdbAuthority(
            $link->sid,
            $link->name,
            new AccountBlockProvider($repository),
            $transport->writeLine(...),
            $udbEpoch ?? UdbAuthority::generateEpoch(),
        );

        $nickServ = new NickServCommandHandler(
            new RegisterNickHandler($repository),
            new IdentifyHandler($repository),
            new S2sAccountLogin($transport, $link->sid),
            new IrcReplySender($transport, $serviceUid),
            new UdbAccountProjection($repository, $authority),
            'NickServ',
            $serviceUid,
        );

        $daemon = new Daemon(
            $runner,
            $transport,
            new IrcMessageParser(),
            new NetworkState(),
            $nickServ,
            new ServiceUidFormatter($link->sid),
            $link->sid,
            $link->name,
            $authority,
        );

        return new self($daemon);
    }

    public function daemon(): Daemon
    {
        return $this->daemon;
    }
}
