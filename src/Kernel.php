<?php

declare(strict_types=1);

namespace Dbots;

use Dbots\Adapter\Out\Transport\TlsStreamTransport;
use Dbots\Application\Daemon;
use Dbots\Application\Link\LinkRunner;
use Dbots\ChanServ\Adapter\In\Irc\ChanServCommandHandler;
use Dbots\ChanServ\Adapter\Out\Persistence\SqliteChannelRepository;
use Dbots\ChanServ\Application\DropChannel\DropChannelHandler;
use Dbots\ChanServ\Application\RegisterChannel\RegisterChannelHandler;
use Dbots\Domain\Link\ServerLink;
use Dbots\Irc\Adapter\In\IrcMessageParser;
use Dbots\Irc\Adapter\Out\Irc\ServiceUidFormatter;
use Dbots\Irc\Application\AccountResolver;
use Dbots\Irc\Application\NetworkState;
use Dbots\MemoServ\Adapter\In\Irc\MemoServCommandHandler;
use Dbots\MemoServ\Adapter\Out\Persistence\SqliteMemoRepository;
use Dbots\MemoServ\Application\DeleteMemo\DeleteMemoHandler;
use Dbots\MemoServ\Application\ReadMemo\ReadMemoHandler;
use Dbots\MemoServ\Application\SendMemo\SendMemoHandler;
use Dbots\NickServ\Adapter\In\Irc\NickServCommandHandler;
use Dbots\NickServ\Adapter\Out\Irc\IrcReplySender;
use Dbots\NickServ\Adapter\Out\Irc\S2sAccountLogin;
use Dbots\NickServ\Adapter\Out\Persistence\SqliteAccountRepository;
use Dbots\NickServ\Application\Identify\IdentifyHandler;
use Dbots\NickServ\Application\RegisterNick\RegisterNickHandler;
use Dbots\Udb\AccountBlockProvider;
use Dbots\Udb\ChannelBlockProvider;
use Dbots\Udb\CompositeBlockProvider;
use Dbots\Udb\UdbAccountProjection;
use Dbots\Udb\UdbAuthority;
use Dbots\Udb\UdbChannelProjection;
use PDO;

use function dirname;
use function is_dir;
use function mkdir;
use function sprintf;

/**
 * Manual wiring. Deliberately no DI container yet: a container would hide more
 * than it explains at this size. Swap it in when the graph hurts.
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

        $pdo = new PDO('sqlite:' . $databasePath);

        $accounts = new SqliteAccountRepository($pdo);
        $channels = new SqliteChannelRepository($pdo);
        $memos = new SqliteMemoRepository($pdo);

        $authority = new UdbAuthority(
            $link->sid,
            $link->name,
            new CompositeBlockProvider([
                new AccountBlockProvider($accounts, $link->name),
                new ChannelBlockProvider($channels),
            ]),
            $transport->writeLine(...),
            $udbEpoch ?? UdbAuthority::generateEpoch(),
        );

        $nickServUid = sprintf('%sA00001', $link->sid);
        $chanServUid = sprintf('%sA00002', $link->sid);
        $memoServUid = sprintf('%sA00003', $link->sid);
        $nickReplies = new IrcReplySender($transport, $nickServUid);
        $chanReplies = new IrcReplySender($transport, $chanServUid);
        $memoReplies = new IrcReplySender($transport, $memoServUid);

        $nickServ = new NickServCommandHandler(
            new RegisterNickHandler($accounts),
            new IdentifyHandler($accounts),
            new S2sAccountLogin($transport, $link->sid),
            $nickReplies,
            new UdbAccountProjection($accounts, $authority),
            'NickServ',
            $nickServUid,
        );

        $chanServ = new ChanServCommandHandler(
            new RegisterChannelHandler($channels),
            new DropChannelHandler($channels),
            $channels,
            new UdbChannelProjection($channels, $authority),
            new AccountResolver($accounts),
            $chanReplies,
            'ChanServ',
            $chanServUid,
        );

        $memoServ = new MemoServCommandHandler(
            new SendMemoHandler($memos, $accounts),
            new ReadMemoHandler($memos),
            new DeleteMemoHandler($memos),
            $memos,
            new AccountResolver($accounts),
            $memoReplies,
            'MemoServ',
            $memoServUid,
        );

        $daemon = new Daemon(
            $runner,
            $transport,
            new IrcMessageParser(),
            new NetworkState(),
            $nickServ,
            $chanServ,
            $memoServ,
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
