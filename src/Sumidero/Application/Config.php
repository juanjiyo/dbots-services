<?php

declare(strict_types=1);

namespace Dbots\Sumidero\Application;

use function explode;
use function in_array;
use function is_numeric;
use function preg_match;
use function strtolower;
use function trim;

/**
 * Environment-driven configuration for the Sumidero module.
 *
 * Every value has a safe default so the module can be loaded without editing
 * configuration files.
 */
final readonly class Config
{
    public string $sink;

    /** @var list<string> */
    public array $watched;

    /** @var list<string> */
    public array $admins;

    public bool $gateEnabled;

    public int $validateSeconds;

    public int $shunTime;

    public int $shunMax;

    public bool $floodEnabled;

    public int $floodMessages;

    public int $floodWindow;

    public int $floodLength;

    public int $floodRepeat;

    public string $verifyUrl;

    public string $verifyBridgeKind;

    public string $bridgeMask;

    public int $tokenLife;

    public function __construct()
    {
        $this->sink = self::string($_ENV['DBOTS_SUMIDERO_SINK'] ?? null, '#Sumidero');
        $this->watched = self::list($_ENV['DBOTS_SUMIDERO_WATCHED'] ?? null);
        $this->admins = self::list($_ENV['DBOTS_SUMIDERO_ADMINS'] ?? null);
        $this->gateEnabled = self::bool($_ENV['DBOTS_SUMIDERO_GATE_ENABLED'] ?? null, true);
        $this->validateSeconds = self::int($_ENV['DBOTS_SUMIDERO_VALIDATE_SECONDS'] ?? null, 30);
        $this->shunTime = self::seconds($_ENV['DBOTS_SUMIDERO_SHUN_TIME'] ?? null, 900);
        $this->shunMax = self::seconds($_ENV['DBOTS_SUMIDERO_SHUN_MAX'] ?? null, 86400);
        $this->floodEnabled = self::bool($_ENV['DBOTS_SUMIDERO_FLOOD_ENABLED'] ?? null, true);
        $this->floodMessages = self::int($_ENV['DBOTS_SUMIDERO_FLOOD_MESSAGES'] ?? null, 6);
        $this->floodWindow = self::int($_ENV['DBOTS_SUMIDERO_FLOOD_WINDOW'] ?? null, 8);
        $this->floodLength = self::int($_ENV['DBOTS_SUMIDERO_FLOOD_LENGTH'] ?? null, 400);
        $this->floodRepeat = self::int($_ENV['DBOTS_SUMIDERO_FLOOD_REPEAT'] ?? null, 3);
        $this->verifyUrl = self::string($_ENV['DBOTS_SUMIDERO_VERIFY_URL'] ?? null, '');
        $this->verifyBridgeKind = strtolower(self::string($_ENV['DBOTS_SUMIDERO_VERIFY_BRIDGE'] ?? null, 'irc'));
        $this->bridgeMask = self::string($_ENV['DBOTS_SUMIDERO_BRIDGE_MASK'] ?? null, 'bot-*@*');
        $this->tokenLife = self::seconds($_ENV['DBOTS_SUMIDERO_TOKEN_LIFE'] ?? null, 1800);
    }

    private static function string(?string $value, string $default): string
    {
        return null === $value || '' === trim($value) ? $default : trim($value);
    }

    /**
     * @return list<string>
     */
    private static function list(?string $value): array
    {
        if (null === $value || '' === trim($value)) {
            return [];
        }

        $items = [];

        foreach (explode(',', $value) as $item) {
            $item = trim($item);

            if ('' !== $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private static function int(?string $value, int $default): int
    {
        if (null === $value || '' === trim($value)) {
            return $default;
        }

        if (!is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }

    private static function bool(?string $value, bool $default): bool
    {
        if (null === $value || '' === trim($value)) {
            return $default;
        }

        return in_array(strtolower(trim($value)), ['1', 'yes', 'y', 'true', 't', 'on', 'si', 'sí', 's'], true);
    }

    private static function seconds(?string $value, int $default): int
    {
        if (null === $value || '' === trim($value)) {
            return $default;
        }

        if (preg_match('/^\s*(\d+)\s*([smhdw]?)\s*$/i', trim($value), $matches) !== 1) {
            return $default;
        }

        $amount = (int) $matches[1];
        $factor = match (strtolower($matches[2])) {
            'w' => 604800,
            'd' => 86400,
            'h' => 3600,
            'm' => 60,
            default => 1,
        };

        return $amount * $factor;
    }
}
