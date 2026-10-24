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

    /**
     * The stored form the module demands: a `b64:` prefix followed by the
     * Base64 of the raw pattern. The prefix is not decoration — the decoder
     * refuses anything else.
     */
    public function encodedPattern(): string
    {
        return 'b64:' . base64_encode($this->pattern);
    }

    /**
     * Property name/value pairs, in the order they must be written.
     *
     * `match-type` goes LAST on purpose: the module validates and applies an F
     * profile when the match-type record is written, so sending it first makes
     * the profile permanently inert.
     *
     * @return list<array{string, string}>
     */
    public function properties(): array
    {
        return [
            ['targets', $this->targets],
            ['action', $this->action],
            ['ban-time', (string) $this->banTime],
            ['reason', $this->reason],
            ['expires', (string) $this->expires],
            ['match-type', 'simple'],
        ];
    }
}
