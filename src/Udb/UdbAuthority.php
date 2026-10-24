<?php

declare(strict_types=1);

namespace Dbots\Udb;

use Dbots\Application\Port\Out\LinkTransportInterface;
use Dbots\Irc\Adapter\In\IrcMessage;

use function array_map;
use function count;
use function explode;
use function implode;
use function in_array;
use function preg_match;
use function random_int;
use function sprintf;

/**
 * UDB 4 authority side: answers HEL, publishes block inventories and serves
 * block snapshots on request. Live mutations (INS) are pushed by the caller.
 *
 * Contracts taken from the module documentation (sections 7 and 9):
 *   :<sid> DB <peer> HEL 4 <selector> <epoch> OCL
 *   :<sid> DB <peer> HEL 4 ACK <selector> <epoch> OCL
 *   :<sid> DB <peer> INF <round> <block> <sha256> <count> 0 <watermark> <root-sid> <root-epoch>
 *   :<sid> DB <peer> RES <round> <block>                (peer -> us)
 *   :<sid> DB <peer> BEGIN|END <round> <block> <txid> <sha256> <watermark>
 *   :<sid> DB <peer> PUT <round> <block> <txid> <Block::path> :<typed-value>
 *   :<sid> DB * INS <epoch> <seq> <Block::path> :<typed-value>
 *
 * Record paths always include the block letter as their first component
 * ("N::alice::pass"), because the digest is computed over that exact path.
 */
final class UdbAuthority
{
    private const string PROTOCOL = '4';
    private const array BLOCKS = ['N', 'C', 'I', 'S', 'L', 'K'];

    private int $sequence = 0;
    private int $round = 0;
    private bool $announced = false;
    private ?string $peerSid = null;

    public function __construct(
        private readonly string $sid,
        private readonly string $serverName,
        private readonly BlockProviderInterface $blocks,
        private readonly LinkTransportInterface $transport,
        private readonly string $epoch,
    ) {
    }

    public static function generateEpoch(): string
    {
        return sprintf('%016x', random_int(0x1000000000000000, 0x7fffffffffffffff));
    }

    public function epoch(): string
    {
        return $this->epoch;
    }

    public function sequence(): int
    {
        return $this->sequence;
    }

    public function handle(IrcMessage $message): void
    {
        if ('DB' !== $message->command) {
            return;
        }

        $args = $message->params;
        $source = $message->source();

        if (null !== $source && 1 !== preg_match('/^[0-9][0-9A-Z]{2}$/', $source)) {
            return;
        }

        // params[0] is the target (us). Replies must go back to the sender.
        if (null !== $source) {
            $this->peerSid = $source;
        }

        match ($args[1] ?? '') {
            'HEL' => $this->onHel($args),
            'RES' => $this->onRes($args),
            default => null,
        };
    }

    /** Publishes a credential so the IRCd can authenticate `NICK nick:password`. */
    public function insertPassword(string $nick, string $passwordHash): void
    {
        $this->insert('N', sprintf('%s::pass', $nick), $passwordHash);
    }

    /**
     * Record paths never include the block letter: the digest and the row
     * components start at the record's own first component.
     */
    /** Removes a record from the IRCd's store. */
    public function delete(string $block, string $path): void
    {
        $this->sequence++;

        $this->transport->writeLine(sprintf(
            ':%s DB * DEL %s %d %s::%s',
            $this->sid,
            $this->epoch,
            $this->sequence,
            $block,
            $this->encodePath($path),
        ));
    }

    public function insert(string $block, string $path, string|int|null $value): void
    {
        $this->sequence++;

        $encodedPath = $this->encodePath($path);

        $this->transport->writeLine(sprintf(
            ':%s DB * INS %s %d %s::%s :%s',
            $this->sid,
            $this->epoch,
            $this->sequence,
            $block,
            $encodedPath,
            NativeDigest::wireValue($value),
        ));
    }

    /** @param list<string> $args */
    private function onHel(array $args): void
    {
        $selector = $args[3] ?? '';

        if ('ACK' === $selector) {
            return;
        }

        $peer = $this->peerSid ?? '*';

        $this->transport->writeLine(sprintf(
            ':%s DB %s HEL %s ACK %s %s OCL',
            $this->sid,
            $peer ?? '*',
            self::PROTOCOL,
            $this->serverName,
            $this->epoch,
        ));

        if (!$this->announced && $selector === $this->serverName) {
            $this->announced = true;
            $this->announceInventory();
        }
    }

    /** @param list<string> $args */
    private function onRes(array $args): void
    {
        $round = (int) ($args[2] ?? 0);
        $block = $args[3] ?? '';

        if ($round <= 0 || !in_array($block, self::BLOCKS, true)) {
            return;
        }

        $records = $this->blocks->records($block);
        $digest = NativeDigest::logicalDigest($block, $records);
        $txid = sprintf('tx%d%s', $round, $block);
        $target = $this->peerSid ?? '*';

        $this->transport->writeLine(sprintf(':%s DB %s BEGIN %d %s %s %s %d', $this->sid, $target, $round, $block, $txid, $digest, $this->sequence));

        foreach ($records as $path => $value) {
            $this->transport->writeLine(sprintf(
                ':%s DB %s PUT %d %s %s %s::%s :%s',
                $this->sid,
                $target,
                $round,
                $block,
                $txid,
                $block,
                $this->encodePath($path),
                NativeDigest::wireValue($value),
            ));
        }

        $this->transport->writeLine(sprintf(':%s DB %s END %d %s %s %s %d', $this->sid, $target, $round, $block, $txid, $digest, $this->sequence));
    }

    private function announceInventory(): void
    {
        $this->round = 1;

        foreach (self::BLOCKS as $block) {
            $records = $this->blocks->records($block);

            $this->transport->writeLine(sprintf(
                ':%s DB %s INF %d %s %s %d 0 %d %s %s',
                $this->sid,
                $this->peerSid ?? '*',
                $this->round,
                $block,
                NativeDigest::logicalDigest($block, $records),
                count($records),
                $this->sequence,
                $this->sid,
                $this->epoch,
            ));
        }
    }

    private function encodePath(string $path): string
    {
        return implode('::', array_map(NativeDigest::encodeComponent(...), explode('::', $path)));
    }
}
