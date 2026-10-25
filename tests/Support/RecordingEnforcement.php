<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use Dbots\OperServ\Application\Port\Out\EnforcementInterface;

final class RecordingEnforcement implements EnforcementInterface
{
    /** @var list<string> */
    public array $kills = [];

    /** @var list<string> */
    public array $kicks = [];

    /** @var list<string> */
    public array $modes = [];

    /** @var list<string> */
    public array $glines = [];

    /** @var list<string> */
    public array $unglines = [];

    /** @var list<string> */
    public array $renames = [];

    /** @var list<int> */
    public array $times = [];

    /** @var list<string> */
    public array $svsJoins = [];

    /** @var list<string> */
    public array $svsParts = [];

    /** @var list<string> */
    public array $userModes = [];

    /** @var list<string> */
    public array $elines = [];

    /** @var list<string> */
    public array $unelines = [];

    /** @var list<string> */
    public array $reservedNicks = [];

    public function kill(string $uid, string $reason): void
    {
        $this->kills[] = $uid . '|' . $reason;
    }

    public function kick(string $channel, string $uid, string $reason): void
    {
        $this->kicks[] = $channel . '|' . $uid . '|' . $reason;
    }

    public function channelMode(string $channel, string $modes, ?string $target = null): void
    {
        $this->modes[] = $channel . '|' . $modes . '|' . ($target ?? '');
    }

    public function gline(string $userMask, string $hostMask, int $durationSeconds, string $reason): void
    {
        $this->glines[] = $userMask . '@' . $hostMask . '|' . $durationSeconds . '|' . $reason;
    }

    public function ungline(string $userMask, string $hostMask): void
    {
        $this->unglines[] = $userMask . '@' . $hostMask;
    }

    public function rename(string $uid, string $newNick): void
    {
        $this->renames[] = $uid . '->' . $newNick;
    }

    public function setTime(int $timestamp): void
    {
        $this->times[] = $timestamp;
    }

    public function svsJoin(string $uid, string $channel): void
    {
        $this->svsJoins[] = $uid . '->' . $channel;
    }

    public function svsPart(string $uid, string $channel): void
    {
        $this->svsParts[] = $uid . '<-' . $channel;
    }

    public function userMode(string $uid, string $modes): void
    {
        $this->userModes[] = $uid . '|' . $modes;
    }

    public function eline(string $userMask, string $hostMask, int $durationSeconds, string $reason): void
    {
        $this->elines[] = $userMask . '@' . $hostMask . '|' . $durationSeconds . '|' . $reason;
    }

    public function uneline(string $userMask, string $hostMask): void
    {
        $this->unelines[] = $userMask . '@' . $hostMask;
    }

    public function reserveNick(string $nick, string $reason): void
    {
        $this->reservedNicks[] = $nick . '|' . $reason;
    }
}
