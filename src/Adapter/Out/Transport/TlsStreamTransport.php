<?php

declare(strict_types=1);

namespace Dbots\Adapter\Out\Transport;

use Dbots\Application\Port\Out\LinkTransportInterface;
use RuntimeException;

use function fclose;
use function feof;
use function fgets;
use function fwrite;
use function is_resource;
use function rtrim;
use function sprintf;
use function stream_context_create;
use function stream_set_timeout;
use function stream_socket_client;

/**
 * TLS stream transport for the S2S link.
 *
 * Blocking and single-link on purpose: one services daemon, one uplink. If the
 * event loop ever needs to multiplex, this is the only class that changes.
 */
final class TlsStreamTransport implements LinkTransportInterface
{
    /** @var resource|null */
    private $stream;

    /**
     * A read timeout is not a disconnect.
     *
     * `feof()` returns true after a timed-out read on a socket, so relying on it
     * made a quiet link look dead: the daemon dropped it every ~30 s and
     * reintroduced its services, which the IRCd then killed as ghosts.
     */
    private bool $closed = false;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly float $timeoutSeconds = 30.0,
        private readonly bool $verifyPeer = true,
    ) {
    }

    public function open(): void
    {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => $this->verifyPeer,
                'verify_peer_name' => $this->verifyPeer,
                'allow_self_signed' => !$this->verifyPeer,
                'peer_name' => $this->host,
            ],
        ]);

        $stream = @stream_socket_client(
            sprintf('tls://%s:%d', $this->host, $this->port),
            $errorCode,
            $errorMessage,
            $this->timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if (false === $stream) {
            throw new RuntimeException(sprintf(
                'Unable to connect to %s:%d: %s (%d)',
                $this->host,
                $this->port,
                $errorMessage,
                $errorCode,
            ));
        }

        stream_set_timeout($stream, (int) $this->timeoutSeconds);
        $this->stream = $stream;
        $this->closed = false;
    }

    public function readLine(): ?string
    {
        if (!$this->isOpen()) {
            return null;
        }

        $line = fgets($this->stream);

        if (false === $line) {
            $meta = stream_get_meta_data($this->stream);

            if (true === $meta['timed_out']) {
                return null;
            }

            $this->closed = true;

            return null;
        }

        return rtrim($line, "\r\n");
    }

    public function writeLine(string $line): void
    {
        if (!$this->isOpen()) {
            throw new RuntimeException('Cannot write on a closed transport.');
        }

        $written = @fwrite($this->stream, $line . "\r\n");

        if (false === $written || '1' === getenv('DBOTS_DEBUG')) {
            fwrite(STDERR, sprintf("WRITE> %s (result: %s)\n", $line, var_export($written, true)));
        }

        if (false === $written) {
            throw new RuntimeException('Failed to write to the link transport.');
        }
    }

    public function isOpen(): bool
    {
        return is_resource($this->stream) && !$this->closed && !feof($this->stream);
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->stream = null;
        $this->closed = true;
    }
}
