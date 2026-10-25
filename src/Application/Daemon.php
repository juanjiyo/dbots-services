<?php

declare(strict_types=1);

namespace Dbots\Application;

use Dbots\Application\Link\LinkRunner;
use Dbots\Application\Port\Out\LinkTransportInterface;
use Dbots\CenTeRServ\Adapter\In\Irc\CenTeRServCommandHandler;
use Dbots\ChanServ\Adapter\In\Irc\ChanServCommandHandler;
use Dbots\CRegServ\Adapter\In\Irc\CRegServCommandHandler;
use Dbots\Domain\Link\LinkState;
use Dbots\HeLPServ\Adapter\In\Irc\HeLPServCommandHandler;
use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Adapter\In\IrcMessageParser;
use Dbots\Irc\Adapter\Out\Irc\ServiceUidFormatter;
use Dbots\Irc\Application\AccountResolver;
use Dbots\Irc\Application\DebugEvents;
use Dbots\Irc\Application\DebugRelay;
use Dbots\Irc\Application\NetworkState;
use Dbots\MemoServ\Adapter\In\Irc\MemoServCommandHandler;
use Dbots\Module\ModuleRuntime;
use Dbots\NickServ\Adapter\In\Irc\NickServCommandHandler;
use Dbots\OperServ\Adapter\In\Irc\OperServCommandHandler;
use Dbots\Udb\UdbAuthority;
use Throwable;

use function array_map;
use function array_slice;
use function explode;
use function in_array;
use function preg_match;
use function sprintf;
use function str_contains;
use function strpos;
use function strtoupper;
use function substr;

/**
 * Runs the daemon: links, introduces the service pseudo-clients and keeps the
 * network view in sync while routing commands.
 */
final readonly class Daemon
{
    public function __construct(
        private LinkRunner $runner,
        private LinkTransportInterface $transport,
        private IrcMessageParser $parser,
        private NetworkState $state,
        private NickServCommandHandler $nickServ,
        private ChanServCommandHandler $chanServ,
        private MemoServCommandHandler $memoServ,
        private OperServCommandHandler $operServ,
        private CRegServCommandHandler $cregServ,
        private HeLPServCommandHandler $helpServ,
        private CenTeRServCommandHandler $centerServ,
        private ServiceUidFormatter $uidFormatter,
        private string $sid,
        private string $serviceHost,
        private ModuleRuntime $modules,
        private ?UdbAuthority $udb = null,
        private ?DebugRelay $debug = null,
        private string $debugChannel = '',
        private ?AccountResolver $accounts = null,
        private ?MemoAnnouncer $memoAnnouncer = null,
    ) {
    }

    public function handshakeState(): \Dbots\Domain\Link\LinkState
    {
        return $this->runner->handshake()->state();
    }

    public function run(?int $seconds): void
    {
        $this->runner->connect(fn (string $line): null => $this->onLine($line));

        $this->registerPeer();

        $this->introduceServices();
        $this->joinDebugChannel();

        $this->runner->pump($seconds, fn (string $line): null => $this->onLine($line));
    }

    public function stop(): void
    {
        $this->runner->close();
    }

    private function introduceServices(): void
    {
        $services = [
            ['NiCK', sprintf('%sA00001', $this->sid), 'Nickname Registration Services'],
            ['CHaN', sprintf('%sA00002', $this->sid), 'Channel Registration Services'],
            ['MeMo', sprintf('%sA00003', $this->sid), 'Memo Services'],
            ['OPeR', sprintf('%sA00004', $this->sid), 'Network Operations Services'],
            ['CReG', sprintf('%sA00006', $this->sid), 'Channel Registration Requests'],
            ['HeLP', sprintf('%sA00007', $this->sid), 'Network Help Services'],
            ['CeNTeR', sprintf('%sA00008', $this->sid), 'Network Control Services'],
        ];

        if (null !== $this->debug && $this->debug->isEnabled()) {
            $services[] = ['DeBuG', sprintf('%sA00005', $this->sid), 'Network Debug Relay'];
        }

        foreach ($services as [$nick, $uid, $realname]) {
            $this->transport->writeLine($this->uidFormatter->format(
                nick: $nick,
                uid: $uid,
                realname: $realname,
                ident: $nick,
                host: $this->serviceHost,
            ));
        }

        $this->modules->introduceAll();
    }

    /** We already know who we linked to from the handshake; record it for display. */
    private function registerPeer(): void
    {
        $handshake = $this->runner->handshake();

        if (null !== $handshake->remoteSid() && null !== $handshake->remoteName()) {
            $this->state->registerServer($handshake->remoteSid(), $handshake->remoteName());
        }
    }

    /** Bulk join during the burst: `:SID SJOIN <ts> <channel> <modes> :<members>`. */
    private function onSjoin(IrcMessage $message): void
    {
        $channel = $message->params[1] ?? null;

        if (null === $channel) {
            return;
        }

        // A SJOIN before EOS replays the whole network, so it must not greet.
        $live = LinkState::Synced === $this->handshakeState();

        $members = [];
        $modes = '';

        foreach (explode(' ', (string) ($message->trailing ?? '')) as $token) {
            if ('' === $token) {
                continue;
            }

            $rank = '';

            while ('' !== $token && str_contains('+%@&~', $token[0])) {
                $rank .= match ($token[0]) {
                    '~' => 'q',
                    '&' => 'a',
                    '@' => 'o',
                    '%' => 'h',
                    '+' => 'v',
                    default => '',
                };
                $token = substr($token, 1);
            }

            $members[] = $token;
            $modes = $rank;
            $this->state->joinChannel($channel, [$token], $rank);

            if ($live) {
                $this->chanServ->onMemberJoined($token, $channel, $this->state);
            }
        }
    }

    private function onJoin(IrcMessage $message): void
    {
        // `:uid JOIN :#channel`: the channel is the trailing part.
        $channel = $message->firstParameter();
        $uid = $message->source();

        if (null !== $channel && null !== $uid) {
            $this->state->joinChannel($channel, [$uid]);

            // The entry message greets the user, the way `chanserv.c.r` does.
            $this->chanServ->onMemberJoined($uid, $channel, $this->state);
        }
    }

    /** `:<source> TOPIC <#channel> [setter ts] :<topic>`, from the IRCd. */
    private function onTopic(IrcMessage $message): void
    {
        $channel = $message->params[0] ?? null;

        if (null === $channel) {
            return;
        }

        $this->chanServ->onTopicChanged(
            (string) $message->source(),
            $channel,
            $message->text(),
            $this->state,
        );
    }

    private function onPart(IrcMessage $message): void
    {
        $channel = $message->firstParameter();
        $uid = $message->source();

        if (null !== $channel && null !== $uid) {
            $this->state->partChannel($channel, $uid);
        }
    }

    private function onKick(IrcMessage $message): void
    {
        $channel = $message->params[0] ?? null;
        $victim = $message->params[1] ?? null;

        if (null !== $channel && null !== $victim) {
            $this->state->partChannel($channel, $victim);
        }
    }

    /** Any other server introduced on the network: `:<parent> SERVER <name> <hops> <sid>`. */
    private function onServer(IrcMessage $message): void
    {
        $name = $message->params[0] ?? null;
        $sid = $message->params[2] ?? null;
        $parentSid = $message->source() ?? $this->runner->handshake()->remoteSid();

        if (null !== $sid && null !== $name && 1 === preg_match('/^[0-9][0-9A-Z]{2}$/', $sid)) {
            $this->state->registerServer($sid, $name, $parentSid);
        }
    }

    /** A server leaves the network: `:<source> SQUIT <sid> :<reason>`. */
    private function onSquit(IrcMessage $message): void
    {
        $sid = $message->params[0] ?? null;

        if (null !== $sid) {
            $this->state->unregisterServer($sid);
        }
    }

    /**
     * The services sit in the debug channel, the way the original ones did: an
     * operator watching the network expects to find them there and to be able to
     * message them without hunting for the nick.
     */
    private function joinDebugChannel(): void
    {
        if (null === $this->debug || !$this->debug->isEnabled()) {
            return;
        }

        $suffixes = ['A00001', 'A00002', 'A00003', 'A00004', 'A00005', 'A00006', 'A00007', 'A00008'];

        foreach ($suffixes as $suffix) {
            $uid = sprintf('%s%s', $this->sid, $suffix);

            $this->transport->writeLine(sprintf(':%s JOIN %s', $uid, $this->debugChannel));
            // SAMODE: a server MODE carries a channel timestamp, and we do not own
            // the channel's clock.
            $this->transport->writeLine(sprintf(':%s SAMODE %s +o %s', $this->sid, $this->debugChannel, $uid));
        }

        $this->modules->joinDebugChannel();
    }

    private function onLine(string $line): null
    {
        $message = $this->parser->parse($line);

        if (null === $message) {
            return null;
        }

        if ('1' === getenv('DBOTS_DEBUG') && in_array($message->command, ['DB', 'PRIVMSG', 'ACCOUNT', 'UID', 'MODE', 'UMODE2', 'JOIN', 'SJOIN'], true)) {
            fwrite(STDERR, "{$message->command}> {$line}\n");
        }

        // Translate first: QUIT removes the user from the state, and a debug line
        // that shows a UID instead of a nick is useless.
        $debugEvent = null !== $this->debug && $this->debug->isEnabled() && 'PRIVMSG' !== $message->command
            ? DebugEvents::translate($message, $this->displayName(...))
            : null;

        match ($message->command) {
            'UID' => $this->onUserIntroduced($message),
            'NICK' => $this->onNickChange($message),
            'QUIT' => $this->onUserQuit($message),
            'DB' => $this->onDbFrame($message, $line),
            'PRIVMSG' => $this->onPrivmsg($message),
            'PING' => $this->onPing($message),
            'ACCOUNT' => $this->state->setAccount($this->resolveUid($message->source()), $message->params[0] ?? null),
            'UMODE2' => $this->onUmode2($message),
            'MODE' => $this->onMode($message),
            'SERVER' => $this->onServer($message),
            'SQUIT' => $this->onSquit($message),
            'SJOIN' => $this->onSjoin($message),
            'JOIN' => $this->onJoin($message),
            'TOPIC' => $this->onTopic($message),
            'PART' => $this->onPart($message),
            'KICK' => $this->onKick($message),
            default => null,
        };

        // The debug channel shows what the services did, not the raw frame.
        if ('PRIVMSG' === $message->command) {
            $this->relayCommand($message);
        } elseif (null !== $debugEvent) {
            $this->debug->event($debugEvent);
        }

        return null;
    }

    private function onDbFrame(IrcMessage $message, string $line): void
    {
        $this->udb?->handle($message);

        // A reconciliation storm would swamp the channel; live mutations are what
        // an operator wants to watch.
        if (str_contains($line, ' INS ') || str_contains($line, ' DEL ')) {
            $this->relayToDebug($line);
        }
    }

    /** Keep the S2S link alive; the IRCd drops silent servers. */
    private function onPing(IrcMessage $message): void
    {
        $target = $message->text();

        if ('' === $target) {
            $target = $message->params[0] ?? '';
        }

        if ('' === $target) {
            return;
        }

        $this->transport->writeLine(sprintf(':%s PONG %s', $this->sid, $target));
    }

    /**
     * Identification arrives as `:nick UMODE2 +r`; that is the moment the user
     * gets told about memos waiting for them.
     */
    private function onUmode2(IrcMessage $message): void
    {
        $uid = $this->resolveUid($message->source());
        $modes = (string) ($message->params[0] ?? '');

        $this->state->applyModeChange($uid, $modes);

        if (!str_contains($modes, '+r')) {
            return;
        }

        $account = $this->accounts?->resolve($uid, $this->state);

        if (null === $account) {
            return;
        }

        $this->memoAnnouncer?->announce($uid, $account);
    }

    /** A mode target arrives as a nick; the membership map is keyed by UID. */
    private function resolveMember(string $id): string
    {
        return $this->state->uidOf($id) ?? $id;
    }

    /** UID to nickname, SID to server name, anything else untouched. */
    private function displayName(string $id): ?string
    {
        return $this->state->nickOf($id) ?? $this->state->serverNameOf($id);
    }

    /** Echoes one protocol line to the debug channel. */
    private function relayToDebug(string $line): void
    {
        if (null !== $this->debug && $this->debug->isEnabled()) {
            $this->debug->mutation($line);
        }
    }

    /** Reports a service command with its actor and the service that handled it. */
    private function relayCommand(IrcMessage $message): void
    {
        if (null === $this->debug || !$this->debug->isEnabled()) {
            return;
        }

        $service = $this->serviceName($message->target());

        if (null === $service) {
            return;
        }

        $text = trim($message->text());
        $space = strpos($text, ' ');
        $verb = strtoupper(false === $space ? $text : substr($text, 0, $space));
        $arguments = false === $space ? '' : substr($text, $space + 1);
        $actor = $this->state->nickOf($message->source() ?? '') ?? 'desconocido';

        if ('' !== $verb) {
            $this->debug->command($actor, $verb, $service, $arguments);
        }
    }

    /** Maps one of our service UIDs (or nicknames) to its display name. */
    private function serviceName(?string $target): ?string
    {
        if (null === $target) {
            return null;
        }

        $known = [
            sprintf('%sA00001', $this->sid) => 'NiCK',
            sprintf('%sA00002', $this->sid) => 'CHaN',
            sprintf('%sA00003', $this->sid) => 'MeMo',
            sprintf('%sA00004', $this->sid) => 'OPeR',
        ];

        return $known[$target] ?? (in_array($target, $known, true) ? $target : null);
    }

    private function onUserIntroduced(IrcMessage $message): void
    {
        $nick = $message->params[0] ?? null;
        $uid = $message->params[5] ?? null;
        $modes = $message->params[7] ?? '';
        $host = $message->params[4] ?? null;

        if (null === $nick || null === $uid) {
            return;
        }

        // A client UID carries the user modes but not the account; identity is
        // derived from +r plus our own account store.
        $this->state->introduceUser($uid, $nick, null, ltrim($modes, '+'), $host);
    }

    /**
     * Sources arrive as a UID for server-originated frames and as a nick for
     * some user-originated ones, so both forms have to resolve to the UID.
     */
    private function resolveUid(?string $source): string
    {
        if (null === $source) {
            return '';
        }

        if (null !== $this->state->nickOf($source)) {
            return $source;
        }

        return $this->state->uidOf($source) ?? $source;
    }

    private function onMode(IrcMessage $message): void
    {
        $target = $message->params[0] ?? null;

        if (null === $target) {
            return;
        }

        if (str_starts_with($target, '#')) {
            // Channel modes name their targets by nick; the state is keyed by UID.
            $changes = (string) ($message->params[1] ?? '');
            $members = array_map($this->resolveMember(...), array_slice($message->params, 2));

            $this->state->applyChannelModeChange($target, $changes, $members);
            $this->chanServ->onChannelModeChanged(
                (string) $message->source(),
                $target,
                $changes,
                $members,
                $this->state,
            );

            return;
        }

        $this->state->applyModeChange($this->resolveUid($target), $message->params[1] ?? '');
    }

    private function onPrivmsg(IrcMessage $message): void
    {
        $this->nickServ->handle($message, $this->state);
        $this->chanServ->handle($message, $this->state);
        $this->memoServ->handle($message, $this->state);
        $this->operServ->handle($message, $this->state);
        $this->cregServ->handle($message, $this->state);
        $this->helpServ->handle($message, $this->state);
        $this->centerServ->handle($message, $this->state);

        foreach ($this->modules->loaded() as $module) {
            try {
                $module->handle($message, $this->state, $this->modules->contextFor($module));
            } catch (Throwable $error) {
                // A broken module must not take the link down.
                fwrite(STDERR, sprintf("module %s failed: %s\n", $module->nick(), $error->getMessage()));
            }
        }


    }

    private function onNickChange(IrcMessage $message): void
    {
        $uid = $message->source();
        $nick = $message->params[0] ?? $message->trailing;

        if (null === $uid || null === $nick) {
            return;
        }

        $this->state->rename($uid, $nick);
    }

    private function onUserQuit(IrcMessage $message): void
    {
        $uid = $message->source();

        if (null !== $uid) {
            $this->state->remove($uid);
        }
    }
}
