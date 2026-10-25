<?php

declare(strict_types=1);

namespace Dbots\OperServ\Application\Port\Out;

interface EnforcementInterface
{
    public function kill(string $uid, string $reason): void;

    public function kick(string $channel, string $uid, string $reason): void;

    /** Applies a channel mode as the server, optionally with a target parameter. */
    public function channelMode(string $channel, string $modes, ?string $target = null): void;

    /** Adds a G-Line; a duration of 0 means permanent. */
    public function gline(string $userMask, string $hostMask, int $durationSeconds, string $reason): void;

    public function ungline(string $userMask, string $hostMask): void;

    /** Forces a connected user to a new nickname. */
    public function rename(string $uid, string $newNick): void;

    /** Sets the network time (UnrealIRCd TSCTL SVSTIME). */
    public function setTime(int $timestamp): void;

    /** Forces a user to join a channel. */
    public function svsJoin(string $uid, string $channel): void;

    /** Forces a user to part a channel. */
    public function svsPart(string $uid, string $channel): void;

    /** Sets user modes on a connected user. */
    public function userMode(string $uid, string $modes): void;

    /**
     * Adds an E-Line exception.
     *
     * UnrealIRCd TKL: `TKL + E <user> <host> <sid> <expire> <set-at> :<reason>`,
     * where expire 0 means permanent.
     */
    public function eline(string $userMask, string $hostMask, int $durationSeconds, string $reason): void;

    /** Removes an E-Line exception. */
    public function uneline(string $userMask, string $hostMask): void;
}
