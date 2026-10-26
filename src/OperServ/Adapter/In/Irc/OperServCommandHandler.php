<?php

declare(strict_types=1);

namespace Dbots\OperServ\Adapter\In\Irc;

use Dbots\ChanServ\Application\Port\Out\ChannelProjectionInterface;
use Dbots\ChanServ\Application\Port\Out\ChannelRepositoryInterface;
use Dbots\Domain\Account\Nick;
use Dbots\Domain\Channel\Channel;
use Dbots\Domain\Channel\ChannelName;
use Dbots\Domain\Spam\SpamRule;
use Dbots\Domain\Staff\StaffRank;
use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Application\AccountResolver;
use Dbots\Irc\Application\Help\HelpCatalog;
use Dbots\Irc\Application\NetworkState;
use Dbots\NickServ\Application\Port\Out\AccountProjectionInterface;
use Dbots\NickServ\Application\Port\Out\AccountRepositoryInterface;
use Dbots\NickServ\Application\Port\Out\ReplyInterface;
use Dbots\OperServ\Application\Duration;
use Dbots\OperServ\Application\Port\Out\ElineRepositoryInterface;
use Dbots\OperServ\Application\Port\Out\EnforcementInterface;
use Dbots\OperServ\Application\Port\Out\SpamRuleRepositoryInterface;
use Dbots\OperServ\Domain\Eline;
use Dbots\Staff\Application\StaffAuthority;
use Dbots\Udb\Application\SetUdbSetting\SetUdbSettingHandler;
use Dbots\Udb\Application\SetUdbSetting\SetUdbSettingResult;
use Dbots\Udb\UdbSettingsProjection;
use Dbots\Udb\UdbSpamProjection;
use InvalidArgumentException;

use function array_filter;
use function array_shift;
use function array_slice;
use function array_values;
use function count;
use function ctype_digit;
use function date;
use function explode;
use function hash_equals;
use function implode;
use function in_array;
use function random_int;
use function sprintf;
use function strcasecmp;
use function strpos;
use function strtoupper;
use function substr;
use function time;

/**
 * IRC operator commands.
 *
 * Every mutating command requires the sender to carry user mode +o; the daemon
 * never infers operator status from a nickname or from who is registered.
 */
final class OperServCommandHandler
{
    /** @var array<string, true> users that authenticated as root during this link */
    private array $roots = [];

    public function __construct(
        private EnforcementInterface $enforcement,
        private AccountRepositoryInterface $accounts,
        private AccountProjectionInterface $accountProjection,
        private ChannelRepositoryInterface $channels,
        private ChannelProjectionInterface $channelProjection,
        private SpamRuleRepositoryInterface $spamRules,
        private UdbSpamProjection $spamProjection,
        private ReplyInterface $reply,
        private string $serviceNick,
        private string $serviceUid,
        private HelpCatalog $help,
        /** @var list<string> hosts that must never be G-Lined by a clone command */
        private array $protectedHosts = ['localhost', '127.0.0.1'],
        private int $killClonesMinutes = 60,
        private int $blockMinutes = 300,
        private string $rootPassword = '',
        private string $jailChannel = '',
        private ?ElineRepositoryInterface $elines = null,
        private ?StaffAuthority $staff = null,
        private ?AccountResolver $resolver = null,
        private ?SetUdbSettingHandler $setUdbSetting = null,
        private ?UdbSettingsProjection $udbSettingsProjection = null,
    ) {
    }

    public function handle(IrcMessage $message, NetworkState $state): void
    {
        if ('PRIVMSG' !== $message->command) {
            return;
        }

        $target = $message->target();

        if (null === $target || !$this->isAddressedToMe($target)) {
            return;
        }

        $sender = $message->source();

        if (null === $sender) {
            return;
        }

        $parts = $this->tokenise($message->text());

        if ([] === $parts) {
            return;
        }

        $command = strtoupper((string) array_shift($parts));

        match ($command) {
            'KILL' => $this->onKill($sender, $parts, $state),
            'KICK' => $this->onKick($sender, $parts, $state),
            'OP' => $this->onOp($sender, $parts, $state, '+o'),
            'DEOP' => $this->onOp($sender, $parts, $state, '-o'),
            'MODE' => $this->onMode($sender, $parts, $state),
            'GLINE' => $this->onGline($sender, $parts, $state),
            'UNGLINE' => $this->onUngline($sender, $parts, $state),
            'RENAME' => $this->onRename($sender, $parts, $state),
            'DROPNDB' => $this->onDropNdb($sender, $parts, $state),
            'APODERA' => $this->onApodera($sender, $parts, $state),
            'LISTCLONES' => $this->onListClones($sender, $parts, $state),
            'KILLCLONES' => $this->onHostBan($sender, $parts, $state, 'killclones'),
            'BLOCK' => $this->onHostBan($sender, $parts, $state, 'block'),
            'JAIL' => $this->onJail($sender, $parts, $state),
            'UMODE' => $this->onUmode($sender, $parts, $state),
            'ELINE' => $this->onEline($sender, $parts, $state),
            'SERVERMAP' => $this->onServermap($sender, $state),
            'CONEXIONES' => $this->onConexiones($sender, $parts, $state),
            'SETTIME' => $this->onSetTime($sender, $state),
            'ROOT' => $this->onRoot($sender, $parts),
            'LIMPIA' => $this->onClean($sender, $parts, $state),
            'SPAM' => $this->onSpam($sender, $parts, $state),
            'STATS' => $this->onStats($sender, $state),
            'IRCOPS' => $this->onIrcops($sender, $state),
            'INVITE' => $this->onInvite($sender, $parts, $state),
            'VIEWIP' => $this->onViewIp($sender, $parts, $state),
            'SET' => $this->onSet($sender, $parts, $state),
            'HELP', 'AYUDA' => $this->onHelp($sender, $parts, $state),
            default => $this->reply->reply($sender, 'Unknown command. Use HELP to list the available commands.'),
        };
    }

    /** @param list<string> $args */
    private function onKill(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireOper($sender, $state)) {
            return;
        }

        $nick = array_shift($args);

        if (null === $nick) {
            $this->reply->reply($sender, 'Syntax: KILL <nick> [reason]');

            return;
        }

        $uid = $state->uidOf($nick);

        if (null === $uid) {
            $this->reply->reply($sender, sprintf('%s is not online.', $nick));

            return;
        }

        $reason = [] === $args ? 'Expulsado por OPeR' : implode(' ', array_values($args));

        $this->enforcement->kill($uid, $reason);
        $this->reply->reply($sender, sprintf('%s has been killed.', $nick));
    }

    /** @param list<string> $args */
    private function onKick(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireOper($sender, $state)) {
            return;
        }

        $channel = $args[0] ?? null;
        $nick = $args[1] ?? null;

        if (null === $channel || null === $nick) {
            $this->reply->reply($sender, 'Syntax: KICK <#channel> <nick> [reason]');

            return;
        }

        $uid = $state->uidOf($nick);

        if (null === $uid) {
            $this->reply->reply($sender, sprintf('%s is not online.', $nick));

            return;
        }

        $reason = implode(' ', array_values(array_slice($args, 2)));

        $this->enforcement->kick($channel, $uid, '' === $reason ? 'Expulsado por OPeR' : $reason);
        $this->reply->reply($sender, sprintf('%s has been kicked from %s.', $nick, $channel));
    }

    /** @param list<string> $args */
    private function onOp(string $sender, array $args, NetworkState $state, string $mode): void
    {
        if (!$this->requireOper($sender, $state)) {
            return;
        }

        $channel = array_shift($args);
        $nick = array_shift($args);

        if (null === $channel || null === $nick) {
            $this->reply->reply($sender, 'Syntax: ' . ('+o' === $mode ? 'OP' : 'DEOP') . ' <#channel> <nick>');

            return;
        }

        $this->enforcement->channelMode($channel, $mode, $nick);
        $this->reply->reply($sender, sprintf('%s%s on %s.', '+o' === $mode ? 'Opped ' : 'Deopped ', $nick, $channel));
    }

    /** @param list<string> $args */
    private function onMode(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireOper($sender, $state)) {
            return;
        }

        $channel = array_shift($args);
        $modes = array_shift($args);

        if (null === $channel || null === $modes) {
            $this->reply->reply($sender, 'Syntax: MODE <#channel> <modes> [argument]');

            return;
        }

        $this->enforcement->channelMode($channel, $modes, $args[0] ?? null);
        $this->reply->reply($sender, sprintf('Mode %s applied to %s.', $modes, $channel));
    }

    /** @param list<string> $args */
    private function onGline(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireOper($sender, $state)) {
            return;
        }

        $mask = array_shift($args);
        $durationValue = array_shift($args);

        if (null === $mask || null === $durationValue) {
            $this->reply->reply($sender, 'Syntax: GLINE <user@host> <duration> [reason]');

            return;
        }

        $duration = Duration::parse($durationValue);

        if (null === $duration) {
            $this->reply->reply($sender, 'Invalid duration. Use minutes, or a suffix: 30m, 2h, 1d, 0 for permanent.');

            return;
        }

        [$user, $host] = $this->splitMask($mask);

        if ('' === $host) {
            $this->reply->reply($sender, 'The mask needs a host part, for example user@host or *@host.');

            return;
        }

        $reason = [] === $args ? 'G-Line puesta por OPeR' : implode(' ', array_values($args));

        $this->enforcement->gline($user, $host, $duration, $reason);
        $this->reply->reply($sender, sprintf(
            'G-Line added on %s@%s (%s).',
            $user,
            $host,
            Duration::describe($duration),
        ));
    }

    /** @param list<string> $args */
    private function onUngline(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireOper($sender, $state)) {
            return;
        }

        $mask = array_shift($args);

        if (null === $mask) {
            $this->reply->reply($sender, 'Syntax: UNGLINE <user@host>');

            return;
        }

        [$user, $host] = $this->splitMask($mask);

        $this->enforcement->ungline($user, $host);
        $this->reply->reply($sender, sprintf('G-Line removed on %s@%s.', $user, $host));
    }

    /** @return array{string, string} user and host parts of a mask */
    private function splitMask(string $mask): array
    {
        $position = strpos($mask, '@');

        if (false === $position) {
            return ['*', $mask];
        }

        return [substr($mask, 0, $position), substr($mask, $position + 1)];
    }

    /** @param list<string> $args */
    private function onRename(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireOper($sender, $state)) {
            return;
        }

        $nick = array_shift($args);

        if (null === $nick) {
            $this->reply->reply($sender, 'Syntax: RENAME <nick> [newnick]');

            return;
        }

        $uid = $state->uidOf($nick);

        if (null === $uid) {
            $this->reply->reply($sender, sprintf('%s is not online.', $nick));

            return;
        }

        $newNick = $args[0] ?? ('Guest' . random_int(1000, 9999));

        $this->enforcement->rename($uid, $newNick);
        $this->reply->reply($sender, sprintf('%s has been renamed to %s.', $nick, $newNick));
    }

    /** @param list<string> $args */
    private function onDropNdb(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireOper($sender, $state)) {
            return;
        }

        $nick = array_shift($args);

        if (null === $nick) {
            $this->reply->reply($sender, 'Syntax: DROPNDB <nick>');

            return;
        }

        try {
            $account = new Nick($nick);
        } catch (InvalidArgumentException) {
            $this->reply->reply($sender, 'That nickname cannot be in the database.');

            return;
        }

        if (null === $this->accounts->findByNick($account)) {
            $this->reply->reply($sender, sprintf('%s is not registered.', $nick));

            return;
        }

        $this->accounts->delete($account);
        $this->accountProjection->remove($account->value);

        $this->reply->reply($sender, sprintf('%s has been removed from the database.', $account->value));
    }

    /** @param list<string> $args */
    private function onApodera(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireOper($sender, $state)) {
            return;
        }

        $channelName = array_shift($args);
        $newFounder = array_shift($args);

        if (null === $channelName) {
            $this->reply->reply($sender, 'Syntax: APODERA <#channel> [nick]');

            return;
        }

        try {
            $registered = $this->channels->find(new ChannelName($channelName));
        } catch (InvalidArgumentException) {
            $this->reply->reply($sender, 'That channel name cannot be registered.');

            return;
        }

        if (null === $registered) {
            $this->reply->reply($sender, sprintf('%s is not registered.', $channelName));

            return;
        }

        $founder = $newFounder ?? $state->nickOf($sender);

        if (null === $founder) {
            $this->reply->reply($sender, 'Say which nick takes the channel.');

            return;
        }

        $this->channels->updateFounder(new Channel(
            $registered->name,
            $founder,
            $registered->createdAt,
            $registered->topic,
            $registered->passwordHash,
            $registered->description,
            $registered->url,
            $registered->email,
            $registered->entryMessage,
            $registered->suspendReason,
            $registered->suspendedBy,
            $registered->suspendedAt,
            $registered->successor,
        ));
        $this->channelProjection->publishRegistered($registered->name->value);

        $this->reply->reply($sender, sprintf('%s now belongs to %s.', $registered->name->value, $founder));
    }

    /** @param list<string> $args */
    private function onListClones(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireOper($sender, $state)) {
            return;
        }

        $nick = array_shift($args);

        if (null === $nick) {
            $this->reply->reply($sender, 'Syntax: LISTCLONES <nick>');

            return;
        }

        $host = $this->hostOf($sender, $nick, $state);

        if (null === $host) {
            return;
        }

        $nicks = $state->nicksOnHost($host);

        $this->reply->reply($sender, sprintf('%s is connected from %s with %d client(s).', $nick, $host, count($nicks)));

        if (count($nicks) > 1) {
            $this->reply->reply($sender, 'Clones: ' . implode(', ', $nicks) . '.');
        }
    }

    /** KILLCLONES and BLOCK both G-Line the host; only the duration differs. */
    private function onHostBan(string $sender, array $args, NetworkState $state, string $command): void
    {
        if (!$this->requireOper($sender, $state)) {
            return;
        }

        $nick = array_shift($args);

        if (null === $nick) {
            $this->reply->reply($sender, sprintf('Syntax: %s <nick> [reason]', strtoupper($command)));

            return;
        }

        $host = $this->hostOf($sender, $nick, $state);

        if (null === $host) {
            return;
        }

        if ($this->isProtectedHost($host)) {
            $this->reply->reply($sender, sprintf('%s is a protected host; refusing to G-Line it.', $host));

            return;
        }

        $minutes = 'killclones' === $command ? $this->killClonesMinutes : $this->blockMinutes;
        $reason = [] === $args
            ? ('killclones' === $command ? 'Clones of ' . $nick : 'Bloqueado por OPeR')
            : implode(' ', array_values($args));

        $this->enforcement->gline('*', $host, $minutes * 60, $reason);
        $this->reply->reply($sender, sprintf(
            '%s from %s G-Lined for %dm.',
            $nick,
            $host,
            $minutes,
        ));
    }

    private function isProtectedHost(string $host): bool
    {
        foreach ($this->protectedHosts as $protected) {
            if (0 === strcasecmp($host, $protected)) {
                return true;
            }
        }

        return false;
    }

    private function hostOf(string $sender, string $nick, NetworkState $state): ?string
    {
        $uid = $state->uidOf($nick);

        if (null === $uid) {
            $this->reply->reply($sender, sprintf('%s is not online.', $nick));

            return null;
        }

        return $state->hostOf($uid);
    }

    /** @param list<string> $args */
    private function onJail(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireOper($sender, $state)) {
            return;
        }

        $nick = array_shift($args);

        if (null === $nick) {
            $this->reply->reply($sender, 'Syntax: JAIL <nick>');

            return;
        }

        $uid = $state->uidOf($nick);

        if (null === $uid) {
            $this->reply->reply($sender, sprintf('%s is not online.', $nick));

            return;
        }

        if ('' === $this->jailChannel) {
            $this->reply->reply($sender, 'Jail channel is not configured.');

            return;
        }

        foreach ($state->channelsOf($uid) as $channel) {
            $this->enforcement->svsPart($uid, $channel);
        }

        $this->enforcement->userMode($uid, '+C');
        $this->enforcement->svsJoin($uid, $this->jailChannel);
        $this->reply->reply($sender, sprintf('%s has been jailed.', $nick));
    }

    /** @param list<string> $args */
    private function onUmode(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireOper($sender, $state)) {
            return;
        }

        $nick = array_shift($args);
        $modes = array_shift($args);

        if (null === $nick || null === $modes) {
            $this->reply->reply($sender, 'Syntax: UMODE <nick> +/-modes');

            return;
        }

        if (str_contains($modes, 'r') || str_contains($modes, 'h')) {
            $this->reply->reply($sender, 'You cannot set or unset the r and h modes.');

            return;
        }

        $uid = $state->uidOf($nick);

        if (null === $uid) {
            $this->reply->reply($sender, sprintf('%s is not online.', $nick));

            return;
        }

        $this->enforcement->userMode($uid, $modes);
        $this->reply->reply($sender, sprintf('User modes %s applied to %s.', $modes, $nick));
    }

    /** @param list<string> $args */
    private function onEline(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireAdmin($sender, $state)) {
            return;
        }

        if (null === $this->elines) {
            $this->reply->reply($sender, 'ELINE repository is not available.');

            return;
        }

        $action = strtoupper((string) array_shift($args));

        match ($action) {
            'ADD' => $this->onElineAdd($sender, $args, $state),
            'DEL' => $this->onElineDel($sender, $args),
            'LIST' => $this->onElineList($sender),
            'VIEW' => $this->onElineView($sender, $args),
            'COUNT' => $this->onElineCount($sender),
            default => $this->reply->reply($sender, 'Syntax: ELINE ADD|DEL|LIST|VIEW|COUNT ...'),
        };
    }

    /** @param list<string> $args */
    private function onElineAdd(string $sender, array $args, NetworkState $state): void
    {
        $mask = array_shift($args);
        $durationValue = array_shift($args);

        if (null === $mask || null === $durationValue || [] === $args) {
            $this->reply->reply($sender, 'Syntax: ELINE ADD <user@host|host> <duración> <motivo>');

            return;
        }

        $duration = Duration::parse($durationValue);

        if (null === $duration) {
            $this->reply->reply($sender, 'Invalid duration. Use minutes, or a suffix: 30m, 2h, 1d, 0 for permanent.');

            return;
        }

        [$user, $host] = $this->splitMask($mask);

        if ('' === $host || false === filter_var($host, FILTER_VALIDATE_IP)) {
            $this->reply->reply($sender, 'The mask needs a valid IP host part, for example *@192.0.2.1.');

            return;
        }

        $canonicalMask = $user . '@' . $host;

        if (null !== $this->elines->findByMask($canonicalMask)) {
            $this->reply->reply($sender, sprintf('An E-Line already exists for %s.', $canonicalMask));

            return;
        }

        $reason = implode(' ', $args);
        $who = $state->nickOf($sender) ?? 'OPeR';
        $now = time();
        $expires = 0 === $duration ? 0 : $now + $duration;

        $this->elines->save(new Eline(null, $canonicalMask, $reason, $who, $now, $expires));
        $this->enforcement->eline($user, $host, $duration, $reason);

        $this->reply->reply($sender, sprintf(
            'E-Line added on %s (%s).',
            $canonicalMask,
            Duration::describe($duration),
        ));
    }

    /** @param list<string> $args */
    private function onElineDel(string $sender, array $args): void
    {
        $mask = array_shift($args);

        if (null === $mask) {
            $this->reply->reply($sender, 'Syntax: ELINE DEL <user@host|host|num>');

            return;
        }

        if (ctype_digit($mask)) {
            $id = (int) $mask;
            $eline = $this->elines->findById($id);

            if (null === $eline) {
                $this->reply->reply($sender, sprintf('No E-Line with number %d.', $id));

                return;
            }

            $this->elines->deleteById($id);
            [$user, $host] = $this->splitMask($eline->mask);
            $this->enforcement->uneline($user, $host);
            $this->reply->reply($sender, sprintf('E-Line %d removed.', $id));

            return;
        }

        [$user, $host] = $this->splitMask($mask);
        $canonicalMask = $user . '@' . $host;

        if (!$this->elines->deleteByMask($canonicalMask)) {
            $this->reply->reply($sender, sprintf('No E-Line matches %s.', $canonicalMask));

            return;
        }

        $this->enforcement->uneline($user, $host);
        $this->reply->reply($sender, sprintf('E-Line removed on %s.', $canonicalMask));
    }

    private function onElineList(string $sender): void
    {
        $elines = $this->elines->allActive(time());

        if ([] === $elines) {
            $this->reply->reply($sender, 'No E-Lines are defined.');

            return;
        }

        $this->reply->reply($sender, 'E-Lines:');

        foreach ($elines as $eline) {
            $this->reply->reply($sender, sprintf(
                '%d. %s (%s)',
                $eline->id,
                $eline->mask,
                Duration::describe(0 === $eline->expiresAt ? 0 : $eline->expiresAt - $eline->createdAt),
            ));
        }
    }

    /** @param list<string> $args */
    private function onElineView(string $sender, array $args): void
    {
        $idValue = array_shift($args);

        if (null === $idValue || !ctype_digit($idValue)) {
            $this->reply->reply($sender, 'Syntax: ELINE VIEW <num>');

            return;
        }

        $eline = $this->elines->findById((int) $idValue);

        if (null === $eline) {
            $this->reply->reply($sender, sprintf('No E-Line with number %d.', $idValue));

            return;
        }

        $this->reply->reply($sender, sprintf('E-Line %d: %s', $eline->id, $eline->mask));
        $this->reply->reply($sender, sprintf('Set by %s on %s.', $eline->who, date('Y-m-d H:i', $eline->createdAt)));
        $this->reply->reply($sender, sprintf('Expires: %s.', 0 === $eline->expiresAt ? 'never' : date('Y-m-d H:i', $eline->expiresAt)));
        $this->reply->reply($sender, sprintf('Reason: %s.', $eline->reason));
    }

    private function onElineCount(string $sender): void
    {
        $count = $this->elines->count();

        $this->reply->reply($sender, 0 === $count
            ? 'No E-Lines are defined.'
            : sprintf('There are %d E-Line(s) defined.', $count));
    }

    private function onServermap(string $sender, NetworkState $state): void
    {
        if (!$this->requireAdmin($sender, $state)) {
            return;
        }

        $roots = $state->rootServers();

        if ([] === $roots) {
            $this->reply->reply($sender, 'No server map available.');

            return;
        }

        foreach ($roots as $root) {
            $this->renderServermap($sender, $state, $root, 0, []);
        }
    }

    /**
     * @param list<bool> $continuations
     */
    private function renderServermap(string $sender, NetworkState $state, string $sid, int $level, array $continuations): void
    {
        $prefix = '';

        for ($i = 0; $i < $level - 1; ++$i) {
            $prefix .= ($continuations[$i] ?? false) ? '  |   ' : '      ';
        }

        if ($level > 0) {
            $prefix .= '  +-- ';
        }

        $name = $state->serverNameOf($sid) ?? $sid;
        $this->reply->reply($sender, $prefix . $name);

        $children = $state->childrenOf($sid);
        $lastIndex = count($children) - 1;

        foreach ($children as $index => $child) {
            $this->renderServermap($sender, $state, $child, $level + 1, [...$continuations, $index !== $lastIndex]);
        }
    }

    /** @param list<string> $args */
    private function onConexiones(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireAdmin($sender, $state)) {
            return;
        }

        $action = strtoupper((string) array_shift($args));
        $param = array_shift($args);

        if ('LIST' === $action) {
            if (null === $param || !ctype_digit($param) || (int) $param <= 1) {
                $this->reply->reply($sender, 'Syntax: CONEXIONES LIST <min>');

                return;
            }

            $hosts = $state->hostsWithAtLeast((int) $param);

            if ([] === $hosts) {
                $this->reply->reply($sender, 'No hosts match that threshold.');

                return;
            }

            $this->reply->reply($sender, sprintf('Hosts with at least %s sessions:', $param));

            foreach ($hosts as $entry) {
                $this->reply->reply($sender, sprintf('%d - %s', $entry['count'], $entry['host']));
            }

            return;
        }

        if ('VIEW' === $action) {
            if (null === $param) {
                $this->reply->reply($sender, 'Syntax: CONEXIONES VIEW <host>');

                return;
            }

            $sessions = $state->sessionsByHost();

            if (!isset($sessions[$param])) {
                $this->reply->reply($sender, sprintf('No sessions found for %s.', $param));

                return;
            }

            $this->reply->reply($sender, sprintf('%s has %d session(s).', $param, $sessions[$param]));

            return;
        }

        $this->reply->reply($sender, 'Syntax: CONEXIONES LIST <min>|VIEW <host>');
    }

    private function onSetTime(string $sender, NetworkState $state): void
    {
        if (!$this->requireRoot($sender, $state)) {
            return;
        }

        $this->enforcement->setTime(time());
        $this->reply->reply($sender, 'Network time resynchronised.');
    }

    /** @param list<string> $args */
    private function onRoot(string $sender, array $args): void
    {
        $password = array_shift($args);

        if ('' === $this->rootPassword) {
            $this->reply->reply($sender, 'Root access is not configured.');

            return;
        }

        if (null === $password) {
            $this->reply->reply($sender, 'Syntax: ROOT <password>');

            return;
        }

        if (!hash_equals($this->rootPassword, $password)) {
            $this->reply->reply($sender, 'Incorrect password.');

            return;
        }

        $this->roots[$sender] = true;
        $this->reply->reply($sender, 'You are now identified as root.');
    }

    /** @param list<string> $args */
    private function onClean(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireOper($sender, $state)) {
            return;
        }

        $channel = array_shift($args);

        if (null === $channel) {
            $this->reply->reply($sender, 'Syntax: LIMPIA <#channel>');

            return;
        }

        // Only parameterless modes: -k and -l would need their arguments, and
        // guessing them is how a "cleanup" ends up breaking the channel.
        $this->enforcement->channelMode($channel, '-imnpst');
        $this->reply->reply($sender, sprintf('%s has been cleaned (modes -imnpst).', $channel));
    }

    /** @param list<string> $args */
    private function onSpam(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireOper($sender, $state)) {
            return;
        }

        $action = strtoupper((string) array_shift($args));

        match ($action) {
            'LIST' => $this->onSpamList($sender),
            'ADD' => $this->onSpamAdd($sender, $args),
            'DEL', 'DELETE' => $this->onSpamDelete($sender, $args),
            default => $this->reply->reply($sender, 'Syntax: SPAM LIST | ADD <pattern> <action> [reason] | DEL <pattern>'),
        };
    }

    private function onSpamList(string $sender): void
    {
        $rules = $this->spamRules->all();

        if ([] === $rules) {
            $this->reply->reply($sender, 'No spamfilter rules are defined.');

            return;
        }

        foreach ($rules as $rule) {
            $this->reply->reply($sender, sprintf(
                '%s -> %s on %s (%s)',
                $rule->pattern,
                $rule->action,
                $rule->targets,
                $rule->reason,
            ));
        }
    }

    /** @param list<string> $args */
    private function onSpamAdd(string $sender, array $args): void
    {
        $pattern = array_shift($args);
        $action = array_shift($args);

        if (null === $pattern || null === $action) {
            $this->reply->reply($sender, 'Syntax: SPAM ADD <pattern> <action> [reason]');

            return;
        }

        if (!in_array($action, SpamRule::ACTIONS, true)) {
            $this->reply->reply($sender, 'Unknown action. Use one of: ' . implode(', ', SpamRule::ACTIONS) . '.');

            return;
        }

        $reason = [] === $args ? 'Spam' : implode(' ', array_values($args));

        $rule = new SpamRule(
            $pattern,
            $action,
            'cpnN',
            3600,
            $reason,
            time() + 30 * 86400,
        );

        $this->spamRules->save($rule);
        $this->spamProjection->publish($pattern);

        $this->reply->reply($sender, sprintf('Spamfilter rule added for %s (%s).', $pattern, $action));
    }

    /** @param list<string> $args */
    private function onSpamDelete(string $sender, array $args): void
    {
        $pattern = array_shift($args);

        if (null === $pattern) {
            $this->reply->reply($sender, 'Syntax: SPAM DEL <pattern>');

            return;
        }

        $removed = $this->spamRules->delete($pattern);

        if ($removed) {
            $this->spamProjection->remove($pattern);
        }

        $this->reply->reply($sender, $removed
            ? sprintf('Spamfilter rule for %s removed.', $pattern)
            : sprintf('No spamfilter rule matches %s.', $pattern));
    }

    private function onStats(string $sender, NetworkState $state): void
    {
        $this->reply->reply($sender, sprintf('Users online: %d.', $state->count()));
    }

    private function onIrcops(string $sender, NetworkState $state): void
    {
        $operators = [];

        foreach ($state->users() as $uid => $nick) {
            if ($state->hasMode($uid, 'o')) {
                $operators[] = $nick;
            }
        }

        $this->reply->reply($sender, [] === $operators
            ? 'No IRC operators are online.'
            : sprintf('IRC operators online: %s.', implode(', ', $operators)));
    }

    /** @param list<string> $args */
    private function onInvite(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireOper($sender, $state)) {
            return;
        }

        $channel = array_shift($args);

        if (null === $channel || 0 !== strpos($channel, '#')) {
            $this->reply->reply($sender, 'Syntax: INVITE <#channel>');

            return;
        }

        $senderNick = $state->nickOf($sender);

        if (null === $senderNick) {
            $this->reply->reply($sender, 'Unable to resolve your nickname.');

            return;
        }

        $this->enforcement->invite($senderNick, $channel);
        $this->reply->reply($sender, sprintf('Invitation sent to %s.', $channel));
    }

    /** @param list<string> $args */
    private function onViewIp(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireOper($sender, $state)) {
            return;
        }

        $nick = array_shift($args);

        if (null === $nick) {
            $this->reply->reply($sender, 'Syntax: VIEWIP <nick>');

            return;
        }

        $uid = $state->uidOf($nick);

        if (null === $uid) {
            $this->reply->reply($sender, sprintf('%s is not online.', $nick));

            return;
        }

        $ip = $state->ipOf($uid);

        if (null === $ip) {
            $this->reply->reply($sender, sprintf('%s real IP is not available.', $nick));

            return;
        }

        $this->reply->reply($sender, sprintf('%s real IP is %s.', $nick, $ip));
    }

    /** @param list<string> $args */
    private function onSet(string $sender, array $args, NetworkState $state): void
    {
        if (!$this->requireOper($sender, $state)) {
            return;
        }

        if (null === $this->setUdbSetting || null === $this->udbSettingsProjection) {
            $this->reply->reply($sender, 'SET is not available.');

            return;
        }

        $option = array_shift($args);
        $value = implode(' ', $args);

        if (null === $option || '' === $value) {
            $this->reply->reply($sender, 'Syntax: SET SUFIJO|NICKSERV|CHANSERV|IPSERV|PASSPROTECT|PREFIJOS <value>');

            return;
        }

        $result = ($this->setUdbSetting)($option, $value);

        match ($result->status) {
            SetUdbSettingResult::SET => $this->onSetPublished($sender, $result->key),
            SetUdbSettingResult::UNKNOWN_OPTION => $this->reply->reply($sender, sprintf('Unknown option: %s.', $option)),
            SetUdbSettingResult::INVALID_VALUE => $this->reply->reply($sender, sprintf('%s', $result->error)),
            default => $this->reply->reply($sender, 'The setting could not be changed.'),
        };
    }

    private function onSetPublished(string $sender, ?string $key): void
    {
        if (null === $key) {
            $this->reply->reply($sender, 'The setting could not be published.');

            return;
        }

        $this->udbSettingsProjection?->publish($key);
        $this->reply->reply($sender, sprintf('Setting %s updated.', $key));
    }

    private function requireOper(string $sender, NetworkState $state): bool
    {
        if ($state->hasMode($sender, 'o') || isset($this->roots[$sender])) {
            return true;
        }

        $this->reply->reply($sender, 'This command is restricted to IRC operators.');

        return false;
    }

    /** Commands such as ELINE need a CeNTeR rank of Administrador or above. */
    private function requireAdmin(string $sender, NetworkState $state): bool
    {
        if (isset($this->roots[$sender])) {
            return true;
        }

        if (null === $this->staff || null === $this->resolver) {
            $this->reply->reply($sender, 'This command requires a CeNTeR rank of Administrador.');

            return false;
        }

        $account = $this->resolver->resolve($sender, $state);

        if ($this->staff->atLeast($account, StaffRank::Administrador)) {
            return true;
        }

        $this->reply->reply($sender, 'This command requires a CeNTeR rank of Administrador.');

        return false;
    }

    /** Root is a level above oper, as in the reference's status levels. */
    private function requireRoot(string $sender, NetworkState $state): bool
    {
        if (isset($this->roots[$sender])) {
            return true;
        }

        $this->reply->reply($sender, 'This command requires root access. Use ROOT <password>.');

        return false;
    }

    /** @param list<string> $args */
    private function onHelp(string $uid, array $args, NetworkState $state): void
    {
        $isOper = $state->hasMode($uid, 'o');

        foreach ($this->help->render(implode(' ', $args), $isOper) as $line) {
            $this->reply->reply($uid, $line);
        }
    }

    private function isAddressedToMe(string $target): bool
    {
        return 0 === strcasecmp($target, $this->serviceNick) || $target === $this->serviceUid;
    }

    /** @return list<string> */
    private function tokenise(string $text): array
    {
        return array_values(array_filter(
            explode(' ', $text),
            static fn (string $token): bool => '' !== $token,
        ));
    }
}
