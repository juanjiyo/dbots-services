<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\OperServ;

use Dbots\Domain\Spam\SpamRule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SpamRuleTest extends TestCase
{
    #[Test]
    public function itEncodesThePatternWithTheB64PrefixTheModuleDemands(): void
    {
        $rule = new SpamRule('hola*', 'gline', 'cpnN', 3600, 'spam', 2_000_000_000);

        self::assertSame('b64:' . base64_encode('hola*'), $rule->encodedPattern());
        self::assertStringStartsWith('b64:', $rule->encodedPattern());
    }

    #[Test]
    public function matchTypeIsWrittenLast(): void
    {
        $rule = new SpamRule('x', 'gline', 'cpnN', 60, 'spam', 2_000_000_000);
        $names = array_map(static fn (array $pair): string => $pair[0], $rule->properties());

        // The module validates and applies the profile when match-type lands.
        self::assertSame('match-type', end($names));
    }

    #[Test]
    public function itExposesEveryPropertyTheProfileNeeds(): void
    {
        $rule = new SpamRule('x', 'gline', 'cpnN', 60, 'spam', 2_000_000_000);
        $names = array_map(static fn (array $pair): string => $pair[0], $rule->properties());

        self::assertSame(['targets', 'action', 'ban-time', 'reason', 'expires', 'match-type'], $names);
    }
}
