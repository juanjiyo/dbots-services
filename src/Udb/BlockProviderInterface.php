<?php

declare(strict_types=1);

namespace Dbots\Udb;

/**
 * Supplies the records of a UDB block as path => typed value.
 *
 * Paths are the part after the block letter: "alice::pass" for N, "#chan::founder" for C.
 */
interface BlockProviderInterface
{
    /** @return array<string, string|int|null> */
    public function records(string $block): array;
}
