<?php

declare(strict_types=1);

namespace Dbots\Udb;

use Dbots\OperServ\Application\Port\Out\SpamRuleRepositoryInterface;

use function sprintf;

/**
 * Projects dynamic Spamfilter rules into the UDB K block.
 *
 * Path shape: `F::<base64-pattern>::<property>`. `match-type` is written last
 * because that record is what makes the module validate and apply the profile.
 */
final readonly class SpamBlockProvider implements BlockProviderInterface
{
    public function __construct(private SpamRuleRepositoryInterface $rules) {}

    public function records(string $block): array
    {
        if ('K' !== $block) {
            return [];
        }

        $records = [];

        foreach ($this->rules->all() as $rule) {
            $prefix = sprintf('F::%s::', $rule->encodedPattern());

            foreach ($rule->properties() as [$name, $value]) {
                $records[$prefix . $name] = 'expires' === $name || 'ban-time' === $name ? (int) $value : $value;
            }
        }

        return $records;
    }
}
