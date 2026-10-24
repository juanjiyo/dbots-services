<?php

declare(strict_types=1);

namespace Dbots\Udb;

use InvalidArgumentException;

use function array_fill;
use function array_slice;
use function base64_encode;
use function chr;
use function count;
use function explode;
use function hash;
use function intdiv;
use function is_int;
use function min;
use function ord;
use function pack;
use function rawurldecode;
use function sprintf;
use function str_pad;
use function strcmp;
use function strlen;
use function strtolower;
use function substr;
use function unpack;
use function usort;

/**
 * Canonical UDB 4 block digest (HEL 4).
 *
 * Port of the reference implementation shipped with the module
 * (tests/support/udb_test_support/native_reference.py). Every byte here is
 * contract: the IRCd compares these digests to decide whether our snapshot is
 * the authority's state, so a single padding mistake breaks the link.
 */
final class NativeDigest
{
    private const int PARTITIONS = 4096;
    private const int LEAF_WEIGHT_LIMIT = 16 * 1024 * 1024;

    /** Percent-encodes a path component exactly like the module. */
    public static function encodeComponent(string $value): string
    {
        $out = '';

        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            $byte = ord($value[$i]);

            if (58 === $byte || 37 === $byte || $byte <= 32 || $byte >= 127) {
                $out .= sprintf('%%%02X', $byte);
                continue;
            }

            $out .= $value[$i];
        }

        return $out;
    }

    public static function wireValue(string|int|null $value): string
    {
        if (null === $value) {
            return '!n';
        }

        return is_int($value) ? '!u' . $value : '!s' . base64_encode($value);
    }

    /**
     * @return list<string>
     */
    public static function components(string $block, string $path): array
    {
        $folded = [];

        foreach (explode('::', $path) as $index => $component) {
            $decoded = rawurldecode($component);

            if ('' === $decoded) {
                throw new InvalidArgumentException('Empty path component.');
            }

            $isSpamfilterKey = 'K' === $block && 1 === $index && 'f' === strtolower(rawurldecode(explode('::', $path)[0]));
            $folded[] = $isSpamfilterKey ? $decoded : strtolower($decoded);
        }

        return $folded;
    }

    /**
     * @param list<string> $parts
     */
    public static function rowEncode(string $block, array $parts, string|int|null $value): string
    {
        $kind = match (true) {
            null === $value => 0,
            is_int($value) => 2,
            default => 1,
        };

        $data = match ($kind) {
            0 => '',
            2 => self::uint64($value),
            default => $value,
        };

        $body = '';

        foreach ($parts as $part) {
            $body .= pack('V', strlen($part)) . $part;
        }

        $body .= $data;

        return chr(ord($block)) . chr(count($parts)) . chr($kind) . chr(0)
            . pack('V', 16 + strlen($body)) . pack('V', strlen($data)) . "\0\0\0\0"
            . $body;
    }

    /**
     * @param list<string> $parts
     */
    public static function keyHash(string $domain, string $block, array $parts): string
    {
        $payload = $domain . "\0" . $block . pack('V', count($parts));

        foreach ($parts as $part) {
            $payload .= pack('V', strlen($part)) . $part;
        }

        return hash('sha256', $payload, true);
    }

    /**
     * @param array<string, string|int|null> $records path => typed value
     */
    public static function logicalDigest(string $block, array $records): string
    {
        /** @var array<int, list<array{list<string>, string, string}>> $partitions */
        $partitions = array_fill(0, self::PARTITIONS, []);
        $depth = 'K' === $block ? 2 : 1;

        foreach ($records as $path => $value) {
            $parts = self::components($block, $path);
            $entity = self::keyHash('UDB4/entity', $block, array_slice($parts, 0, $depth));
            $partition = (ord($entity[0]) << 4) | (ord($entity[1]) >> 4);
            $partitions[$partition][] = [$parts, self::keyHash('UDB4/key', $block, $parts), self::rowEncode($block, $parts, $value)];
        }

        $result = "UDB4/block\0" . $block;
        $totalCount = 0;
        $totalWeight = 0;

        foreach ($partitions as $partition => $rows) {
            $summary = self::leaf($block, $partition, $rows);
            $count = self::readUint64($summary, 32);
            $weight = self::readUint64($summary, 40);

            $totalCount += $count;
            $totalWeight += $weight;
            $result .= pack('v', $partition) . self::uint64($count) . self::uint64($weight) . substr($summary, 0, 32);
        }

        $result .= self::uint64($totalCount) . self::uint64($totalWeight);

        return hash('sha256', $result);
    }

    /**
     * @param list<array{list<string>, string, string}> $rows
     */
    private static function leaf(string $block, int $partition, array $rows, int $depth = 0, string $prefix = ''): string
    {
        $count = count($rows);
        $weight = 0;

        foreach ($rows as $row) {
            $weight += strlen($row[2]) + 128;
        }

        $position = $block . pack('v', $partition) . pack('v', $depth) . str_pad($prefix, 32, "\0", STR_PAD_LEFT);
        $totals = self::uint64($count) . self::uint64($weight);

        if ($weight <= self::LEAF_WEIGHT_LIMIT) {
            usort($rows, self::compareRows(...));
            $content = '';

            foreach ($rows as $row) {
                $content .= pack('V', strlen($row[2])) . $row[2];
            }

            return hash('sha256', "UDB4/leaf\0" . $position . $content . $totals, true) . $totals;
        }

        if (256 === $depth) {
            throw new InvalidArgumentException('Hash collision over leaf bound.');
        }

        $bitIndex = 255 - $depth;
        $byteIndex = 31 - intdiv($bitIndex, 8);
        $left = [];
        $right = [];

        foreach ($rows as $row) {
            if (0 !== (ord($row[1][$byteIndex]) & (1 << ($bitIndex % 8)))) {
                $right[] = $row;
                continue;
            }

            $left[] = $row;
        }

        $children = self::leaf($block, $partition, $left, $depth + 1, $prefix)
            . self::leaf($block, $partition, $right, $depth + 1, self::withBit($prefix, $byteIndex, $bitIndex % 8));

        return hash('sha256', "UDB4/branch\0" . $position . $children . $totals, true) . $totals;
    }

    private static function withBit(string $prefix, int $byteIndex, int $bitInByte): string
    {
        $bytes = str_pad($prefix, 32, "\0", STR_PAD_LEFT);
        $bytes[$byteIndex] = chr(ord($bytes[$byteIndex]) | (1 << $bitInByte));

        return $bytes;
    }

    /**
     * Python tuple ordering: components bytewise, then key hash, then row.
     *
     * @param array{list<string>, string, string} $left
     * @param array{list<string>, string, string} $right
     */
    private static function compareRows(array $left, array $right): int
    {
        $byParts = self::compareParts($left[0], $right[0]);

        if (0 !== $byParts) {
            return $byParts;
        }

        $byHash = strcmp($left[1], $right[1]);

        return 0 !== $byHash ? $byHash : strcmp($left[2], $right[2]);
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     */
    private static function compareParts(array $left, array $right): int
    {
        $shared = min(count($left), count($right));

        for ($i = 0; $i < $shared; $i++) {
            $comparison = strcmp($left[$i], $right[$i]);

            if (0 !== $comparison) {
                return $comparison;
            }
        }

        return count($left) <=> count($right);
    }

    public static function uint64(int $value): string
    {
        return pack('P', $value);
    }

    private static function readUint64(string $buffer, int $offset): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('P', substr($buffer, $offset, 8));

        return $unpacked[1];
    }
}
