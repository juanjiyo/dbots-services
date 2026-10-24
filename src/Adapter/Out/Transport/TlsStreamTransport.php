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
    }

    public function readLine(): ?string
    {
        if (!$this->isOpen()) {
            return null;
        }

        $line = fgets($this->stream);

        if (false === $line) {
            return null;
        }

        return rtrim($line, "\r\n");
    }

    public function writeLine(string $line): void
    {
        if (!$this->isOpen()) {
            throw new RuntimeException('Cannot write on a closed transport.');
        }

        if (false === fwrite($this->stream, $line . "\r\n")) {
            throw new RuntimeException('Failed to write to the link transport.');
        }
    }

    public function isOpen(): bool
    {
        return is_resource($this->stream) && !feof($this->stream);
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->stream = null;
    }
}
