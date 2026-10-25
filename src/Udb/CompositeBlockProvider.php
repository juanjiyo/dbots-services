<?php

declare(strict_types=1);

namespace Dbots\Udb;

/**
 * Merges several providers, one per block owner.
 *
 * Merging (rather than a single provider that knows every aggregate) keeps each
 * service unaware of the others while still presenting one six-block view.
 */
final readonly class CompositeBlockProvider implements BlockProviderInterface
{
    /** @param list<BlockProviderInterface> $providers */
    public function __construct(private array $providers)
    {
    }

    public function records(string $block): array
    {
        $records = [];

        foreach ($this->providers as $provider) {
            foreach ($provider->records($block) as $path => $value) {
                $records[$path] = $value;
            }
        }

        return $records;
    }
}
