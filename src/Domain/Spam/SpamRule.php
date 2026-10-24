<?php

declare(strict_types=1);

namespace Dbots\Domain\Spam;

use function base64_encode;

/**
 * A dynamic Spamfilter rule, materialised as a UDB K::F profile.
 *
 * The pattern component is Base64 of the raw pattern; UDB keys fold ASCII case
 * except the K::F pattern itself, so the encoded form is the identity.
 */
final readonly class SpamRule
{
    /** Canonical target letters, in the order the module requires. */
    public const string CANONICAL_TARGETS = 'cpnNPqduatTR';

    /** Actions UnrealIRCd accepts as dynamic (config-only actions are not). */
    public const array ACTIONS = ['gline', 'gzline', 'shun', 'kill', 'block', 'warn', 'tempshun'];

    public function __construct(
        public string $pattern,
        public string $action,
        public string $targets,
        public int $banTime,
        public string $reason,
        public int $expires,
    ) {
    }

    public function encodedPattern(): string
    {
        return base64_encode($this->pattern);
    }

    /** @return list<string> property name and value pairs, in a stable order */
    public function properties(): array
    {
        return [
            ['match-type', 'simple'],
            ['targets', $this->targets],
            ['action', $this->action],
            ['ban-time', (string) $this->banTime],
            ['reason', $this->reason],
            ['expires', (string) $this->expires],
        ];
    }
}
