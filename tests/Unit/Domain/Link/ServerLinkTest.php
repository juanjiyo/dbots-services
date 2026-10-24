<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\Domain\Link;

use Dbots\Domain\Link\ServerLink;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ServerLinkTest extends TestCase
{
    #[Test]
    public function itAcceptsAValidLinkConfiguration(): void
    {
        $link = new ServerLink('bots.example.org', '003', 'secret');

        self::assertSame('bots.example.org', $link->name);
        self::assertSame('003', $link->sid);
        self::assertSame('dbots', $link->description);
    }

    #[Test]
    public function itRejectsANameWithoutADot(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ServerLink('bots', '003', 'secret');
    }

    #[Test]
    public function itRejectsAnInvalidSid(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ServerLink('bots.example.org', 'A03', 'secret');
    }

    #[Test]
    public function itRejectsAnEmptyPassword(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ServerLink('bots.example.org', '003', '   ');
    }

    #[Test]
    public function itRejectsAnEmptyDescription(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ServerLink('bots.example.org', '003', 'secret', '');
    }
}
