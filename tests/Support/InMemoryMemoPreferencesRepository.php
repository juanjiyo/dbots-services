<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use Dbots\Domain\Memo\MemoNotifyMode;
use Dbots\MemoServ\Application\Port\Out\MemoPreferencesRepositoryInterface;

use function array_values;
use function strcasecmp;
use function strtolower;

final class InMemoryMemoPreferencesRepository implements MemoPreferencesRepositoryInterface
{
    /** @var array<string, string> */
    private array $modes = [];

    /** @var array<string, list<string>> */
    private array $blacklists = [];

    public function getNotifyMode(string $account): MemoNotifyMode
    {
        $key = strtolower($account);

        if (!isset($this->modes[$key])) {
            return MemoNotifyMode::On;
        }

        return MemoNotifyMode::fromString($this->modes[$key]) ?? MemoNotifyMode::On;
    }

    public function setNotifyMode(string $account, MemoNotifyMode $mode): void
    {
        $this->modes[strtolower($account)] = $mode->value;
    }

    public function isBlacklisted(string $recipient, string $sender): bool
    {
        foreach ($this->blacklist($recipient) as $blocked) {
            if (0 === strcasecmp($blocked, $sender)) {
                return true;
            }
        }

        return false;
    }

    public function addToBlacklist(string $recipient, string $sender): void
    {
        if ($this->isBlacklisted($recipient, $sender)) {
            return;
        }

        $this->blacklists[strtolower($recipient)][] = $sender;
    }

    public function removeFromBlacklist(string $recipient, string $sender): bool
    {
        $key = strtolower($recipient);

        if (!isset($this->blacklists[$key])) {
            return false;
        }

        foreach ($this->blacklists[$key] as $index => $blocked) {
            if (0 === strcasecmp($blocked, $sender)) {
                unset($this->blacklists[$key][$index]);
                $this->blacklists[$key] = array_values($this->blacklists[$key]);

                return true;
            }
        }

        return false;
    }

    public function listBlacklist(string $recipient): array
    {
        return $this->blacklist($recipient);
    }

    /** @return list<string> */
    private function blacklist(string $recipient): array
    {
        return $this->blacklists[strtolower($recipient)] ?? [];
    }
}
