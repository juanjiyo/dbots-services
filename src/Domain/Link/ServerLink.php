<?php

declare(strict_types=1);

namespace Dbots\Domain\Link;

use InvalidArgumentException;

use function preg_match;
use function sprintf;
use function trim;

/**
 * Everything the daemon needs to present itself to an IRCd as a services server.
 */
final readonly class ServerLink
{
    private const string NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9.-]*\.[A-Za-z]{2,}$/';
    private const string SID_PATTERN = '/^[0-9][0-9A-Z]{2}$/';

    public function __construct(
        public string $name,
        public string $sid,
        public string $password,
        public string $description = 'dbots',
    ) {
        if (1 !== preg_match(self::NAME_PATTERN, $name)) {
            throw new InvalidArgumentException(sprintf('Invalid server name "%s": expected a dotted FQDN.', $name));
        }

        if (1 !== preg_match(self::SID_PATTERN, $sid)) {
            throw new InvalidArgumentException(sprintf('Invalid SID "%s": expected one digit followed by two characters.', $sid));
        }

        if ('' === trim($password)) {
            throw new InvalidArgumentException('The link password must not be empty.');
        }

        if ('' === trim($description)) {
            throw new InvalidArgumentException('The server description must not be empty.');
        }
    }
}
