<?php

declare(strict_types=1);

namespace Dbots\Domain\Channel;

/**
 * The per-channel level table, as ChatHispano runs it: one numeric threshold per
 * command, from -1 (never) to 500 (founder or IRCop only).
 *
 * `ch.mrc` also had a `DIS` sentinel for "disabled"; the reference table uses 500
 * for the same meaning, so there is only one value to reason about here.
 */
enum ChannelLevel: string
{
    case AutoOp = 'AUTOOP';
    case AutoHalfOp = 'AUTOHALFOP';
    case AutoVoice = 'AUTOVOICE';
    case AutoDeOp = 'AUTODEOP';
    case AutoDeVoice = 'AUTODEVOICE';
    case NoJoin = 'NOJOIN';
    case Invite = 'INVITE';
    case OpDeOp = 'OPDEOP';
    case HalfOpDeHalfOp = 'HALFOPDEHALFOP';
    case VoiceDeVoice = 'VOICEDEVOICE';
    case Unban = 'UNBAN';
    case Topic = 'TOPIC';
    case AkickChange = 'AKICK-CHANGE';
    case AkickList = 'AKICK-LIST';
    case Clear = 'CLEAR';
    case Set = 'SET';
    case AccList = 'ACC-LIST';
    case AccChange = 'ACC-CHANGE';
    case MemoRecv = 'MEMO-RECV';
    case MemoSend = 'MEMO-SEND';
    case Identify = 'IDENTIFY';

    /** The threshold a channel starts with, from the SitioChat reference table. */
    public function default(): int
    {
        return match ($this) {
            self::AutoOp => 300,
            self::AutoHalfOp => 200,
            self::AutoVoice => 100,
            self::AutoDeOp => 200,
            self::AutoDeVoice => -1,
            self::NoJoin => -1,
            self::Invite => 300,
            self::OpDeOp => 100,
            self::HalfOpDeHalfOp => 200,
            self::VoiceDeVoice => 100,
            self::Unban => 300,
            self::Topic => 500,
            self::AkickChange => 499,
            self::AkickList => 300,
            self::Clear => 500,
            self::Set => 500,
            self::AccList => 499,
            self::AccChange => 499,
            self::MemoRecv => 500,
            self::MemoSend => 0,
            self::Identify => 0,
        };
    }

    public static function fromName(string $name): ?self
    {
        return self::tryFrom(strtoupper($name));
    }

    /** @return array<string, int> */
    public static function defaults(): array
    {
        $defaults = [];

        foreach (self::cases() as $case) {
            $defaults[$case->value] = $case->default();
        }

        return $defaults;
    }
}
