<?php

declare(strict_types=1);

namespace Dbots\Irc\Application;

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
    /** @var array<string, string> uid => nick */
    private array $nicks = [];

    /** @var array<string, string> uid => account */
    private array $accounts = [];

    /** @var array<string, string> uid => user modes */
    private array $modes = [];

    public function introduceUser(string $uid, string $nick, ?string $account = null, string $modes = ''): void
    {
        $this->nicks[$uid] = $nick;
        $this->modes[$uid] = $modes;

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
        unset($this->nicks[$uid], $this->accounts[$uid], $this->modes[$uid]);
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
    }

    /** Unused today; kept so callers can normalise account names once they exist. */
    public static function normalise(string $value): string
    {
        return strtoupper($value);
    }
}
