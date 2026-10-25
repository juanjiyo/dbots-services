<?php

declare(strict_types=1);

namespace Dbots\Irc\Application;

use function array_keys;
use function in_array;
use function str_replace;
use function str_split;
use function strtolower;
use function strtoupper;

/**
 * Minimal view of who is online, built from the server burst.
 *
 * UnrealIRCd introduces users by UID, so the sender of a PRIVMSG is a UID, not
 * a nickname. Without this map there is no way to know which nick asked, and
 * without the account there is no way to know who is identified.
 */
final class NetworkState
{
    /** @var array<string, array<string, true>> channel => ban mask => true */
    private array $bans = [];

    /** @var array<string, string> uid => nick */
    private array $nicks = [];

    /** @var array<string, string> uid => account */
    private array $accounts = [];

    /** @var array<string, string> uid => user modes */
    private array $modes = [];

    /** @var array<string, string> uid => host */
    private array $hosts = [];

    /** @var array<string, string> sid => server name */
    private array $servers = [];

    /** @var array<string, array<string, string>> channel => uid => member modes */
    private array $channels = [];

    public function introduceUser(string $uid, string $nick, ?string $account = null, string $modes = '', ?string $host = null): void
    {
        $this->nicks[$uid] = $nick;
        $this->modes[$uid] = $modes;

        if (null !== $host) {
            $this->hosts[$uid] = $host;
        }

        if (null !== $account) {
            $this->accounts[$uid] = $account;
        }
    }

    public function setModes(string $uid, string $modes): void
    {
        $this->modes[$uid] = $modes;
    }

    /** Applies a mode string such as "+r" or "+i-x" to the user's stored modes. */
    public function applyModeChange(string $uid, string $changes): void
    {
        $current = $this->modes[$uid] ?? '';
        $adding = true;

        for ($i = 0, $length = strlen($changes); $i < $length; $i++) {
            $character = $changes[$i];

            if ('+' === $character) {
                $adding = true;
                continue;
            }

            if ('-' === $character) {
                $adding = false;
                continue;
            }

            if ($adding && !str_contains($current, $character)) {
                $current .= $character;
                continue;
            }

            if (!$adding) {
                $current = str_replace($character, '', $current);
            }
        }

        $this->modes[$uid] = $current;
    }

    /** True when the user carries the given user mode, e.g. "r" for identified. */
    public function hasMode(string $uid, string $mode): bool
    {
        return str_contains($this->modes[$uid] ?? '', $mode);
    }

    public function rename(string $uid, string $newNick): void
    {
        if (isset($this->nicks[$uid])) {
            $this->nicks[$uid] = $newNick;
        }
    }

    public function setAccount(string $uid, ?string $account): void
    {
        if (null === $account) {
            unset($this->accounts[$uid]);

            return;
        }

        $this->accounts[$uid] = $account;
    }

    public function accountOf(string $uid): ?string
    {
        return $this->accounts[$uid] ?? null;
    }

    public function remove(string $uid): void
    {
        unset($this->nicks[$uid], $this->accounts[$uid], $this->modes[$uid], $this->hosts[$uid]);
    }

    public function hostOf(string $uid): ?string
    {
        return $this->hosts[$uid] ?? null;
    }

    /** @return list<string> nicks connected from that host */
    public function nicksOnHost(string $host): array
    {
        $nicks = [];

        foreach ($this->hosts as $uid => $known) {
            if (0 === strcasecmp($known, $host) && isset($this->nicks[$uid])) {
                $nicks[] = $this->nicks[$uid];
            }
        }

        return $nicks;
    }

    public function registerServer(string $sid, string $name): void
    {
        if ('' !== $sid && '' !== $name) {
            $this->servers[$sid] = $name;
        }
    }

    /** Resolves a server SID to its name; the debug channel should never show raw SIDs. */
    public function serverNameOf(string $sid): ?string
    {
        return $this->servers[$sid] ?? null;
    }

    /** @param list<string> $uids members to add with the given prefix modes */
    public function joinChannel(string $channel, array $uids, string $modes = ''): void
    {
        foreach ($uids as $uid) {
            $this->channels[$channel][$uid] = $modes;
        }
    }

    public function partChannel(string $channel, string $uid): void
    {
        unset($this->channels[$channel][$uid]);
    }

    /**
     * A channel MODE names its targets, and a target is always a member, so the
     * mode stream is a second source of membership. It matters: when the IRCd
     * creates a channel from a UDB profile it sends no SJOIN, only modes.
     *
     * @param list<string> $params
     */
    public function applyChannelModeChange(string $channel, string $changes, array $params): void
    {
        $adding = true;
        $index = 0;

        foreach (str_split($changes) as $mode) {
            if ('+' === $mode) {
                $adding = true;

                continue;
            }

            if ('-' === $mode) {
                $adding = false;

                continue;
            }

            if (!in_array($mode, ['q', 'a', 'o', 'h', 'v'], true)) {
                // `b` carries a mask we can remember; the rest are not tracked.
                if (in_array($mode, ['b', 'e', 'I', 'k'], true)) {
                    $param = $params[$index] ?? null;
                    ++$index;

                    if ('b' === $mode && null !== $param) {
                        $this->applyBan($channel, $param, $adding);
                    }
                }

                continue;
            }

            $uid = $params[$index] ?? null;
            ++$index;

            if (null === $uid) {
                continue;
            }

            if ($adding) {
                $this->channels[$channel][$uid] = (($this->channels[$channel][$uid] ?? '').$mode);

                continue;
            }

            // Removing a rank still proves membership, which is how we learn
            // about the first joiner of a channel created from a UDB profile.
            $this->channels[$channel][$uid] = str_replace($mode, '', $this->channels[$channel][$uid] ?? '');
        }
    }

    /** @return list<string> members carrying the given rank, as UIDs */
    public function membersWithRank(string $channel, string $rank): array
    {
        $members = [];

        foreach ($this->channels[$channel] ?? [] as $uid => $modes) {
            if (str_contains($modes, $rank)) {
                $members[] = $uid;
            }
        }

        return $members;
    }

    /** @return list<string> every member of the channel, as UIDs */
    public function membersOf(string $channel): array
    {
        return array_keys($this->channels[$channel] ?? []);
    }

    private function applyBan(string $channel, string $mask, bool $adding): void
    {
        if ($adding) {
            $this->bans[$channel][$mask] = true;

            return;
        }

        unset($this->bans[$channel][$mask]);
    }

    /** @return list<string> */
    public function bansOf(string $channel): array
    {
        return array_keys($this->bans[$channel] ?? []);
    }

    /** @return list<string> every channel the user is in, as channel names */
    public function channelsOf(string $uid): array
    {
        $channels = [];

        foreach ($this->channels as $channel => $members) {
            if (isset($members[$uid])) {
                $channels[] = $channel;
            }
        }

        return $channels;
    }

    public function isInChannel(string $channel, string $uid): bool
    {
        return isset($this->channels[$channel][$uid]);
    }

    /** True when the user is in the channel with operator status (+o or higher). */
    public function isChannelOperator(string $channel, string $uid): bool
    {
        $modes = $this->channels[$channel][$uid] ?? null;

        if (null === $modes) {
            return false;
        }

        foreach (['q', 'a', 'o'] as $rank) {
            if (str_contains($modes, $rank)) {
                return true;
            }
        }

        return false;
    }

    public function isServer(string $id): bool
    {
        return isset($this->servers[$id]);
    }

    public function nickOf(string $uid): ?string
    {
        return $this->nicks[$uid] ?? null;
    }

    public function uidOf(string $nick): ?string
    {
        $needle = strtolower($nick);

        foreach ($this->nicks as $uid => $known) {
            if ($needle === strtolower($known)) {
                return $uid;
            }
        }

        return null;
    }

    public function hasNick(string $nick): bool
    {
        return null !== $this->uidOf($nick);
    }

    /** @return array<string, string> uid => nick */
    public function users(): array
    {
        return $this->nicks;
    }

    public function count(): int
    {
        return \count($this->nicks);
    }

    public function forgetAll(): void
    {
        $this->nicks = [];
        $this->accounts = [];
        $this->modes = [];
        $this->hosts = [];
        $this->servers = [];
        $this->channels = [];
    }

    /** Unused today; kept so callers can normalise account names once they exist. */
    public static function normalise(string $value): string
    {
        return strtoupper($value);
    }
}
