<?php

declare(strict_types=1);

namespace Dbots\Domain\Account;

use InvalidArgumentException;

use function preg_match;
use function sprintf;

final readonly class Nick
{
    /** IRC nickname grammar, with a 30-character ceiling. */
    private const string PATTERN = '/^[A-Za-z\[\]\\\\`_^{|}][A-Za-z0-9\[\]\\\\`_^{|}-]{0,29}$/';

    public string $value;

    public function __construct(string $value)
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw new InvalidArgumentException(sprintf('Invalid nickname "%s".', $value));
        }

        $this->value = $value;
    }

    public function equals(self $other): bool
    {
        return 0 === strcasecmp($this->value, $other->value);
    }
}
