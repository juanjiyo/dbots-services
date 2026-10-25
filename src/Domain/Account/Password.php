<?php

declare(strict_types=1);

namespace Dbots\Domain\Account;

use InvalidArgumentException;

use function password_hash;
use function password_verify;
use function sprintf;
use function strlen;
use function trim;

use const PASSWORD_DEFAULT;

/**
 * A plaintext password that is only ever held long enough to be hashed or verified.
 */
final readonly class Password
{
    public const int MIN_LENGTH = 6;

    private function __construct(private string $plain)
    {
    }

    public static function fromPlain(string $plain): self
    {
        if (strlen(trim($plain)) < self::MIN_LENGTH) {
            throw new InvalidArgumentException(sprintf('The password must be at least %d characters long.', self::MIN_LENGTH));
        }

        return new self($plain);
    }

    public function hash(): string
    {
        return password_hash($this->plain, PASSWORD_DEFAULT);
    }

    public function matches(string $hash): bool
    {
        return password_verify($this->plain, $hash);
    }
}
