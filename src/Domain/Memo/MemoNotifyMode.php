<?php

declare(strict_types=1);

namespace Dbots\Domain\Memo;

/**
 * When MeMoServ tells a user about unread memos.
 */
enum MemoNotifyMode: string
{
    case On = 'ON';
    case Logon = 'LOGON';
    case New = 'NEW';
    case Off = 'OFF';

    /**
     * @return list<self>
     */
    public static function values(): array
    {
        return [self::On, self::Logon, self::New, self::Off];
    }

    public static function fromString(string $value): ?self
    {
        return self::tryFrom(strtoupper($value));
    }

    public function announcesAtLogin(): bool
    {
        return self::On === $this || self::Logon === $this;
    }

    public function announcesOnNewMemo(): bool
    {
        return self::On === $this || self::New === $this;
    }
}
