<?php

declare(strict_types=1);

namespace Dbots\Bootstrap;

use Dbots\Application\Daemon;
use Dbots\Application\Link\LinkRunner;
use Dbots\Application\Port\Out\LinkTransportInterface;
use Dbots\ChanServ\Adapter\In\Irc\ChanServCommandHandler;
use Dbots\ChanServ\Application\DropChannel\DropChannelHandler;
use Dbots\ChanServ\Application\Help\ChanServHelp;
use Dbots\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use Dbots\ChanServ\Application\Port\Out\ChannelProjectionInterface;
use Dbots\ChanServ\Application\Port\Out\ChannelRepositoryInterface;
use Dbots\ChanServ\Application\RegisterChannel\RegisterChannelHandler;
use Dbots\Domain\Link\ServerLink;
use Dbots\Irc\Adapter\In\IrcMessageParser;
use Dbots\Irc\Adapter\Out\Irc\ServiceUidFormatter;
use Dbots\Irc\Application\AccountResolver;
use Dbots\Irc\Application\DebugRelay;
use Dbots\Irc\Application\NetworkState;
use Dbots\MemoServ\Adapter\In\Irc\MemoServCommandHandler;
use Dbots\MemoServ\Application\DeleteMemo\DeleteMemoHandler;
use Dbots\MemoServ\Application\Help\MemoServHelp;
use Dbots\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use Dbots\MemoServ\Application\ReadMemo\ReadMemoHandler;
use Dbots\MemoServ\Application\SendMemo\SendMemoHandler;
use Dbots\NickServ\Adapter\In\Irc\NickServCommandHandler;
use Dbots\NickServ\Adapter\Out\Irc\IrcReplySender;
use Dbots\NickServ\Adapter\Out\Persistence\SqliteAccountRepository;
use Dbots\NickServ\Application\AccountInfo\AccountInfoHandler;
use Dbots\NickServ\Application\ChangePassword\ChangePasswordHandler;
use Dbots\NickServ\Application\DropNick\DropNickHandler;
use Dbots\NickServ\Application\Help\NickServHelp;
use Dbots\NickServ\Application\Identify\IdentifyHandler;
use Dbots\NickServ\Application\Port\Out\AccountProjectionInterface;
use Dbots\NickServ\Application\Port\Out\AccountRepositoryInterface;
use Dbots\NickServ\Application\RegisterNick\RegisterNickHandler;
use Dbots\OperServ\Adapter\In\Irc\OperServCommandHandler;
use Dbots\OperServ\Adapter\Out\Irc\S2sEnforcement;
use Dbots\OperServ\Application\Help\OperServHelp;
use Dbots\OperServ\Application\Port\Out\SpamRuleRepositoryInterface;
use Dbots\Udb\AccountBlockProvider;
use Dbots\Udb\BlockProviderInterface;
use Dbots\Udb\SpamBlockProvider;
use Dbots\Udb\UdbAccountProjection;
use Dbots\Udb\UdbAuthority;
use Dbots\Udb\UdbChannelProjection;
use Dbots\Udb\UdbSpamProjection;

use function sprintf;

/**
 * Assembles the daemon graph.
 *
 * Symfony's container owns the object graph; this factory only exists to build
 * the values that cannot be autowired — the per-service nicknames and UIDs
 * derived from the server SID — and to keep that derivation in one place.
 */
final readonly class DaemonFactory
{
    public function __construct(
        private ServerLink $link,
        private LinkTransportInterface $transport,
        private AccountRepositoryInterface $accounts,
        private ChannelRepositoryInterface $channels,
        private ChannelAccessRepositoryInterface $channelAccess,
        private MemoRepositoryInterface $memos,
        private SpamRuleRepositoryInterface $spamRules,
        private BlockProviderInterface $blocks,
        private string $rootPassword = '',
        private string $debugChannel = '',
        private int $syncTimeoutSeconds = 30,
    ) {
    }

    public static function epoch(): string
    {
        return UdbAuthority::generateEpoch();
    }

    public function create(): Daemon
    {
        $sid = $this->link->sid;
        $host = $this->link->name;

        $nickServUid = sprintf('%sA00001', $sid);
        $chanServUid = sprintf('%sA00002', $sid);
        $memoServUid = sprintf('%sA00003', $sid);
        $operServUid = sprintf('%sA00004', $sid);

        $authority = new UdbAuthority(
            $sid,
            $host,
            $this->blocks,
            $this->transport,
            self::epoch(),
        );

        $accountProjection = new UdbAccountProjection(
            $this->accounts,
            $authority,
            new AccountBlockProvider($this->accounts, $host),
        );
        $channelProjection = new UdbChannelProjection($this->channels, $authority);
        $spamProjection = new UdbSpamProjection($this->spamRules, $authority);

        $nickServ = new NickServCommandHandler(
            new RegisterNickHandler($this->accounts),
            new IdentifyHandler($this->accounts),
            new IrcReplySender($this->transport, $nickServUid),
            $accountProjection,
            new ChangePasswordHandler($this->accounts),
            new DropNickHandler($this->accounts, $this->channels),
            new AccountResolver($this->accounts),
            new AccountInfoHandler($this->accounts, $this->channels, $this->memos),
            $this->accounts,
            'NickServ',
            $nickServUid,
            NickServHelp::create(),
        );

        $chanServ = new ChanServCommandHandler(
            new RegisterChannelHandler($this->channels),
            new DropChannelHandler($this->channels, $this->channelAccess),
            $this->channels,
            $this->channelAccess,
            $channelProjection,
            new AccountResolver($this->accounts),
            new IrcReplySender($this->transport, $chanServUid),
            'ChanServ',
            $chanServUid,
            ChanServHelp::create(),
        );

        $memoServ = new MemoServCommandHandler(
            new SendMemoHandler($this->memos, $this->accounts),
            new ReadMemoHandler($this->memos),
            new DeleteMemoHandler($this->memos),
            $this->memos,
            new AccountResolver($this->accounts),
            new IrcReplySender($this->transport, $memoServUid),
            'MemoServ',
            $memoServUid,
            MemoServHelp::create(),
        );

        $operServ = new OperServCommandHandler(
            new S2sEnforcement($this->transport, $sid),
            $this->accounts,
            $accountProjection,
            $this->channels,
            $channelProjection,
            $this->spamRules,
            $spamProjection,
            new IrcReplySender($this->transport, $operServUid),
            'OperServ',
            $operServUid,
            OperServHelp::create(),
            rootPassword: $this->rootPassword,
        );

        $debug = new DebugRelay($this->transport, sprintf('%sA00005', $sid), $this->debugChannel);

        return new Daemon(
            new LinkRunner($this->link, $this->transport, $this->syncTimeoutSeconds),
            $this->transport,
            new IrcMessageParser(),
            new NetworkState(),
            $nickServ,
            $chanServ,
            $memoServ,
            $operServ,
            new ServiceUidFormatter($sid),
            $sid,
            $host,
            $authority,
            $debug,
            $this->debugChannel,
        );
    }
}
