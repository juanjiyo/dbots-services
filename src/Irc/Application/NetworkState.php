<?php

declare(strict_types=1);

namespace Dbots\Irc\Application;

use function strtolower;
use function strtoupper;

/**
 * Minimal view of who is online, built from the server burst.
 *
 * UnrealIRCd introduces users by UID, so the sender of a PRIVMSG is a UID, not
 * a nickname. Without this map there is no way to know which nick asked.
 */
final class NetworkState
{
    /** @var array<string, string> uid => nick */
    private array $nicks = [];

    public function introduceUser(string $uid, string $nick): void
    {
        $this->nicks[$uid] = $nick;
    }

    public function rename(string $uid, string $newNick): void
    {
        if (isset($this->nicks[$uid])) {
            $this->nicks[$uid] = $newNick;
        }
    }

    public function remove(string $uid): void
    {
        unset($this->nicks[$uid]);
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

    public function count(): int
    {
        return \count($this->nicks);
    }

    public function forgetAll(): void
    {
        $this->nicks = [];
    }

    /** Unused today; kept so callers can normalise account names once they exist. */
    public static function normalise(string $value): string
    {
        return strtoupper($value);
    }
}
