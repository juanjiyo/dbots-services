<?php

declare(strict_types=1);

namespace Dbots\Domain\Channel;

use InvalidArgumentException;

use function preg_match;
use function sprintf;
use function strtolower;

final readonly class ChannelName
{
    /** IRC channel grammar: # followed by up to 31 characters without space, comma or colon. */
    private const string PATTERN = '/^#[^\s,:]{1,31}$/';

    public string $value;

    public function __construct(string $value)
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw new InvalidArgumentException(sprintf('Invalid channel name "%s".', $value));
        }

        $this->value = strtolower($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
