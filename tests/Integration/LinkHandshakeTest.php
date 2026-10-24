<?php

declare(strict_types=1);

namespace Dbots\Tests\Integration;

use Dbots\Adapter\Out\Transport\TlsStreamTransport;
use Dbots\Application\Link\LinkRunner;
use Dbots\Domain\Link\LinkState;
use Dbots\Domain\Link\ServerLink;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Live check against a running UnrealIRCd. Opt-in:
 *
 *   DBOTS_LINK_PASSWORD=openaccess vendor/bin/phpunit --group integration
 *
 * The IRCd needs a link block for DBOTS_LINK_NAME accepting the local address,
 * and the server must be listed in ulines.
 */
#[Group('integration')]
final class LinkHandshakeTest extends TestCase
{
    #[Test]
    public function itLinksAndSyncsAgainstALiveIrcd(): void
    {
        $password = (string) getenv('DBOTS_LINK_PASSWORD');

        if ('' === $password) {
            self::markTestSkipped('Set DBOTS_LINK_PASSWORD to run the live IRCd check.');
        }

        $link = new ServerLink(
            name: (string) (getenv('DBOTS_LINK_NAME') ?: 'bots.sitiochat.es'),
            sid: (string) (getenv('DBOTS_LINK_SID') ?: '009'),
            password: $password,
            description: 'dbots integration test',
        );

        $runner = new LinkRunner(
            $link,
            new TlsStreamTransport(
                host: (string) (getenv('DBOTS_LINK_HOST') ?: '127.0.0.1'),
                port: (int) (getenv('DBOTS_LINK_PORT') ?: '6900'),
                verifyPeer: false,
            ),
        );

        try {
            $runner->connect();

            self::assertSame(LinkState::Synced, $runner->handshake()->state());
            self::assertNotNull($runner->handshake()->remoteSid());
        } finally {
            $runner->close();
        }
    }
}
